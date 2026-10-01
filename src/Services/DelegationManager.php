<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Services;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Controller\PhysicianClaimController;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Exception\DelegationRefusedException;
use Pixiekat\HMFPSearchToolBundle\Repository\PhysicianDelegationRepository;
use Pixiekat\SymfonyHelpers\Services\AuditLogManager;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Everything that CHANGES a delegation: invite, accept, decline, remove.
 *
 * A service rather than controller code, for three reasons:
 *
 *   - The same acts happen from two controllers (the claimant's page and the
 *     steward's), and one copy of "what removing a delegate means" beats two.
 *   - PhysicianClaimController needs endAllForClaim() when a claim is revoked.
 *   - It is the one place that knows how a brand-new invitee gets a login —
 *     see activateAccount(), which is where Entra SSO plugs in later.
 *
 * The entity owns the state machine (what may follow what); this owns the
 * side effects (accounts, flushes, audit rows, email). Controllers own HTTP:
 * CSRF, flashes and redirects.
 *
 * Refusals the person should hear about are thrown as
 * DelegationRefusedException with a sentence ready for a flash message.
 */
class DelegationManager {

  /**
   * The shortest password an invitee may choose.
   *
   * Twelve, following current NIST guidance (SP 800-63B) of favouring length
   * over composition rules: no "must contain a symbol", which mostly produces
   * Password1! — just long enough that a passphrase is the easy option.
   */
  public const MIN_PASSWORD_LENGTH = 12;

  /**
   * The longest. Not a security limit — a denial-of-service one: hashing is
   * deliberately slow, and slow times a megabyte of input is slower still.
   * 4096 is Symfony's own PasswordHasher ceiling.
   */
  public const MAX_PASSWORD_LENGTH = 4096;

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly PhysicianDelegationRepository $delegations,
    private readonly EmailDomainPolicy $domainPolicy,
    private readonly UserPasswordHasherInterface $passwordHasher,
    private readonly AuditLogManager $auditLogManager,
    private readonly MailerInterface $mailer,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {  }

  /**
   * Invites $rawEmail to help with the profile $claim covers, and emails them.
   *
   * Four outcomes, and the caller does not need to know which happened — the
   * person asking is told "invitation sent" either way, so the form cannot be
   * used to find out whether an address has an account here:
   *
   *   1. No account at that address → one is created, INACTIVE, with no
   *      password, and the email invites them to set it up.
   *   2. An account created by an EARLIER invitation that has not been set up
   *      yet → same as 1; this invitation may activate it too.
   *   3. An ordinary active account → the email asks them to accept or decline.
   *   4. An invitation to this person is already pending → it is re-sent with a
   *      fresh link, and the old link stops working.
   *
   * @throws DelegationRefusedException with a user-facing sentence.
   */
  public function invite(Entity\PhysicianClaim $claim, string $rawEmail, Entity\User $inviter): Entity\PhysicianDelegation {
    if (!$claim->grantsEditing()) {
      // The controller only reaches here through the live claim, so this is a
      // race — the claim was revoked between page load and submit.
      throw new DelegationRefusedException('You no longer hold this profile, so you cannot invite anybody to it.');
    }

    // Lower-cased for storage and comparison. The local part of an address is
    // technically case-sensitive, but no mail system anybody here uses treats it
    // so, and "Jane.Doe@" and "jane.doe@" becoming two accounts would be a real
    // problem where the RFC's letter is a theoretical one.
    $email = mb_strtolower(trim($rawEmail));

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new DelegationRefusedException('That does not look like an email address. Please check it and try again.');
    }

    if (!$this->domainPolicy->isAllowed($email)) {
      $listed = $this->domainPolicy->listedDomains();

      throw new DelegationRefusedException($listed === []
        // Fail-closed, and says so plainly rather than looking like the
        // invitee's fault.
        ? 'Invitations are not available yet: no email domains have been approved. Please ask an administrator.'
        : sprintf('Only addresses at %s can be invited.', implode(', ', $listed)));
    }

    if ($email === mb_strtolower($claim->getUser()->getEmailAddress())) {
      throw new DelegationRefusedException('That is your own address — you can already edit this profile.');
    }

    // The users table's collation is case-insensitive, so this finds
    // "Jane.Doe@…" when asked for "jane.doe@…" as well.
    $delegate = $this->entityManager->getRepository(Entity\User::class)->findOneBy(['emailAddress' => $email]);
    $existing = $delegate !== null ? $this->delegations->openDelegationFor($claim, $delegate) : null;

    if ($existing !== null && $existing->getStatus()->isAccepted()) {
      throw new DelegationRefusedException(sprintf('%s is already a delegate for this profile.', $email));
    }

    if ($existing === null && $this->delegations->countOpenForClaim($claim) >= Entity\PhysicianDelegation::MAX_OPEN_PER_CLAIM) {
      throw new DelegationRefusedException(sprintf(
        'A profile can have at most %d delegates and open invitations at once. Remove one before inviting somebody new.',
        Entity\PhysicianDelegation::MAX_OPEN_PER_CLAIM,
      ));
    }

    $createdAccount = false;

    if ($delegate === null) {
      $delegate = $this->createInvitedAccount($email);
      $createdAccount = true;
      $activatesAccount = true;
    }
    elseif ($delegate->isInactive()) {
      // Case 2, or an account an administrator switched off. Told apart by HOW
      // the account came to exist: only one an invitation created, and nobody
      // has since set up, may be activated by an invitation.
      $activatesAccount = $delegate->getPassword() === null
        && $this->delegations->wasCreatedByInvitation($delegate);

      if (!$activatesAccount) {
        throw new DelegationRefusedException(sprintf(
          'The account for %s has been switched off. Please ask an administrator.',
          $email,
        ));
      }
    }
    else {
      $activatesAccount = false;
    }

    $delegation = $existing ?? new Entity\PhysicianDelegation($claim, $delegate, $inviter, $activatesAccount);
    $token      = $delegation->issueToken();

    $this->entityManager->persist($delegation);

    try {
      // Flushed before auditing, so the rows have ids to be audited BY — see the
      // matching note in PhysicianClaimController::request().
      $this->entityManager->flush();
    }
    catch (UniqueConstraintViolationException) {
      // UNIQ_PHYSDELEG_OPEN or the users email index: two submissions of the same
      // invitation raced (a double-click, or two tabs). The first one won and has
      // already sent its email. The entity manager is closed now, so nothing more
      // can be written in this request — which is fine, there is nothing to add.
      throw new DelegationRefusedException(sprintf('An invitation to %s was just sent. Check the list below.', $email));
    }

    if ($createdAccount) {
      $this->auditLogManager->log('delegation.account_created', $delegate, [
        'email'       => $email,
        'invitedBy'   => $inviter->getUserIdentifier(),
        'physicianId' => $claim->getPhysician()->getId(),
      ], flush: false);
    }

    $this->auditLogManager->log($existing !== null ? 'delegation.reinvited' : 'delegation.invited', $delegation, [
      'physicianId'      => $claim->getPhysician()->getId(),
      'delegate'         => $delegation->getDelegateLabel(),
      'invitedBy'        => $inviter->getUserIdentifier(),
      'activatesAccount' => $delegation->activatesAccount(),
    ]);

    // After the flush, for PhysicianClaimController::request()'s reason: never
    // email a token that is not yet in the database.
    $this->sendInvitation($delegation, $token);

    return $delegation;
  }

  /**
   * Problems with a chosen password, as sentences. Empty means acceptable.
   *
   * A list rather than a bool so the page can say WHAT is wrong — "too short"
   * and "the two do not match" need different fixes, and a form that only says
   * "invalid" makes people guess.
   *
   * @return list<string>
   */
  public function passwordProblems(string $password, string $confirmation): array {
    $problems = [];
    $length   = mb_strlen($password);

    if ($length < self::MIN_PASSWORD_LENGTH) {
      $problems[] = sprintf('Your password must be at least %d characters long. A few ordinary words strung together is a good way to get there.', self::MIN_PASSWORD_LENGTH);
    }
    elseif ($length > self::MAX_PASSWORD_LENGTH) {
      $problems[] = sprintf('Your password must be at most %d characters long.', self::MAX_PASSWORD_LENGTH);
    }

    if (!hash_equals($password, $confirmation)) {
      $problems[] = 'The two passwords do not match. Please type the same password in both boxes.';
    }

    return $problems;
  }

  /**
   * The delegate says yes. For an invitation that activates an account,
   * $plainPassword is the password they chose, and the account is switched on.
   *
   * The caller has already checked the token and — for an existing account —
   * that the person signed in is the delegate. This method does not repeat
   * that: it cannot see the request.
   *
   * @throws DelegationRefusedException when the password is unacceptable.
   */
  public function accept(Entity\PhysicianDelegation $delegation, ?string $plainPassword = null, ?string $confirmation = null): void {
    $activating = $delegation->canActivateAccount();

    if ($activating) {
      $problems = $this->passwordProblems((string) $plainPassword, (string) $confirmation);

      if ($problems !== []) {
        throw new DelegationRefusedException(implode(' ', $problems));
      }

      $this->activateAccount($delegation->getDelegate(), (string) $plainPassword);
    }

    $delegation->accept();

    if ($activating) {
      $this->auditLogManager->log('delegation.account_activated', $delegation->getDelegate(), [
        'physicianId' => $delegation->getPhysician()->getId(),
      ], flush: false);
    }

    $this->auditLogManager->log('delegation.accepted', $delegation, [
      'physicianId' => $delegation->getPhysician()->getId(),
      'delegate'    => $delegation->getDelegateLabel(),
    ], flush: false);

    $this->entityManager->flush();
  }

  /**
   * The delegate says no.
   *
   * An account created for this invitation is left as it is — inactive, with no
   * password, unable to sign in. Deleting it would CASCADE this row away, and
   * "they were asked and said no" is precisely the history worth keeping. A
   * periodic prune of never-activated accounts is a reasonable later addition.
   */
  public function decline(Entity\PhysicianDelegation $delegation): void {
    $delegation->decline('Declined by the invitee.');

    $this->auditLogManager->log('delegation.declined', $delegation, [
      'physicianId' => $delegation->getPhysician()->getId(),
      'delegate'    => $delegation->getDelegateLabel(),
    ], flush: false);

    $this->entityManager->flush();
  }

  /**
   * The claimant or a steward removes a delegate, or withdraws an invitation.
   */
  public function revoke(Entity\PhysicianDelegation $delegation, Entity\User $by, ?string $note = null): void {
    $delegation->revoke($by, $note);

    $this->auditLogManager->log('delegation.revoked', $delegation, [
      'physicianId' => $delegation->getPhysician()->getId(),
      'delegate'    => $delegation->getDelegateLabel(),
      'by'          => $by->getUserIdentifier(),
    ], flush: false);

    $this->entityManager->flush();
  }

  /**
   * Closes every open delegation under a claim that is being revoked.
   *
   * NOT what cuts the delegates off — the voter's query does that the moment the
   * claim stops granting, whether or not this runs. This is so the rows tell the
   * truth afterwards: without it the claimant's history, and the steward's
   * screen, would go on saying "Active delegate" under a claim that no longer
   * exists.
   *
   * Does NOT flush: it is called from inside PhysicianClaimController's revoke
   * actions, which flush the claim and these together.
   *
   * @return int How many were closed.
   */
  public function endAllForClaim(Entity\PhysicianClaim $claim, ?Entity\User $by, string $note): int {
    $ended = 0;

    foreach ($this->delegations->findOpenForClaim($claim) as $delegation) {
      $delegation->revoke($by, $note);
      $ended++;
    }

    return $ended;
  }

  /**
   * Gives a brand-new invitee their first way of signing in.
   *
   * THE seam for Entra SSO, kept to one method so that swap is one change.
   *
   * Today: set the password they chose, and switch the account on.
   *
   * With Entra, the plan is:
   *   1. The invitation email for a new account says "Sign in with Microsoft"
   *      instead of "choose a password".
   *   2. Following the link while signed out sends them through SSO rather than
   *      showing the password form (PhysicianDelegationController::respondForm()
   *      is where that branch goes), and brings them back to the link.
   *   3. The SSO authenticator finds the inactive invited account by email —
   *      case-insensitively; Entra's `email`/`preferred_username` claim can
   *      differ in case from what the claimant typed — ACTIVATES it (this method,
   *      minus the password), and records the Entra object id (`oid`) on the user
   *      so later sign-ins match on oid rather than on an address that can change.
   *   4. They land on the accept/decline page as an ordinary signed-in user.
   *
   * One thing to settle with the directory team first: invite people at their
   * PRIMARY address. If the claimant types an alias (jane.doe@) and Entra reports
   * the UPN (jdoe@), step 3 finds no match and SSO would create a second account.
   */
  private function activateAccount(Entity\User $user, string $plainPassword): void {
    $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
    $user->activate();
  }

  /**
   * A new account for an invited address: inactive, no password, ROLE_USER.
   *
   * Cannot sign in until activated — the UserChecker refuses inactive accounts,
   * and a null password cannot be checked against anything.
   */
  private function createInvitedAccount(string $email): Entity\User {
    $user = new Entity\User();
    $user->setEmailAddress($email);
    $user->setRoles(['ROLE_USER']);

    // User::__construct() switches accounts ON by default, which is right for an
    // administrator creating one and wrong here.
    $user->deactivate();

    $this->entityManager->persist($user);

    return $user;
  }

  /**
   * Emails the invitation link.
   *
   * Two templates rather than one with branches: somebody being asked to set up
   * a brand-new account needs a different explanation from somebody who
   * already signs in here, and a single template trying to be both reads like
   * neither.
   *
   * Failures are swallowed and logged, for the reason given on
   * PhysicianClaimController::sendConfirmation(): the row is committed, and the
   * claimant can re-send from the delegates page.
   */
  private function sendInvitation(Entity\PhysicianDelegation $delegation, string $token): void {
    $delegate = $delegation->getDelegate();
    $template = $delegation->canActivateAccount()
      ? '@HMFPSearchTool/delegation/invite_new_account_email.html.twig'
      : '@HMFPSearchTool/delegation/invite_existing_account_email.html.twig';

    try {
      $email = (new TemplatedEmail())
        ->from(PhysicianClaimController::MAIL_FROM)
        ->to($delegate->getEmailAddress())
        ->subject(sprintf('You have been invited to help with %s\'s HMFP Search Tool profile', $delegation->getPhysician()->getLegalName()))
        ->htmlTemplate($template)
        ->context([
          'delegation'   => $delegation,
          'physician'    => $delegation->getPhysician(),
          'inviterLabel' => $delegation->getInviterLabel(),
          'expiresAt'    => $delegation->getTokenExpiresAt(),
          'respondUrl'   => $this->urlGenerator->generate(
            'hmfp_search_tool_delegation_respond_form',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
          ),
          'supportEmail' => PhysicianClaimController::SUPPORT_EMAIL,
        ]);

      $this->mailer->send($email);

      $this->auditLogManager->log('delegation.emailed', $delegation, [
        'physicianId' => $delegation->getPhysician()->getId(),
        'delegate'    => $delegation->getDelegateLabel(),
      ]);
    }
    catch (\Throwable $e) {
      $this->auditLogManager->logToLogger('delegation.mail_failed', $delegation, [
        'physicianId' => $delegation->getPhysician()->getId(),
        'error'       => $e->getMessage(),
      ], \Psr\Log\LogLevel::ERROR);
    }
  }
}
