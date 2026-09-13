<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum\ClaimMethod;
use Pixiekat\HMFPSearchToolBundle\Enum\ClaimStatus;
use Pixiekat\HMFPSearchToolBundle\Interfaces;
use Pixiekat\HMFPSearchToolBundle\Repository;
use Pixiekat\HMFPSearchToolBundle\Services;
use Pixiekat\SymfonyHelpers\Services\AuditLogManager;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Claiming a physician record: "are you this physician?"
 *
 * Four steps, deliberately boring:
 *
 *   1. GET  /physicians/{id}/claim         the offer, and what it means
 *   2. POST /physicians/{id}/claim         creates a Pending claim, emails a link
 *   3. GET  /claims/confirm/{token}        the link — asks for the NPI
 *   4. POST /claims/confirm/{token}        checks the NPI, links the account
 *
 * Worth being precise about what this establishes, because it is easy to
 * overrate. Accounts here are created by SSO or by an administrator — there is
 * no registration form — so the address on the account came from Entra and the
 * claimant already proved they control that mailbox by signing in. The email
 * round trip therefore adds a RECORD and a DELAY rather than a new factor. And
 * the NPI is published by CMS in a free public registry, so knowing it is not a
 * secret either.
 *
 * What the combination does establish: an authenticated account inside the
 * organisation deliberately asserted this identity, at a recorded time, and knew
 * which NPI belonged to the person it claimed to be. That is a fair bar for
 * "may propose edits to a moderated field", and it is not a bar for anything
 * that publishes without review.
 *
 * No JavaScript anywhere. Every step is a form post with a CSRF token, and the
 * flow works identically with scripting switched off.
 */
final class PhysicianClaimController extends AbstractController {

  /**
   * Who confirmation mail comes from.
   *
   * Matching HmfpSearchToolAuthCodeMailer's hard-coded sender rather than
   * inventing a second convention. Both want to become a bound parameter —
   * see the note in that class.
   */
  private const MAIL_FROM = 'kebloom@bidmc.harvard.edu';

  /**
   * Where to send someone who needs a human.
   */
  private const SUPPORT_EMAIL = 'help@alicantobidmc.org';

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly Repository\PhysicianRepository $physicians,
    private readonly Repository\PhysicianClaimRepository $claims,
    private readonly AuditLogManager $auditLogManager,
    private readonly MailerInterface $mailer,
    private readonly UrlGeneratorInterface $urlGenerator,
    private readonly Repository\PhysicianEditRepository $edits,
    private readonly Services\PhysicianEditManager $editManager,
  ) {  }

  /**
   * A steward vouches for a claim by hand.
   *
   * approve() rather than verify(), for two reasons.
   *
   * Meaning: ClaimStatus::Verified means the SELF-SERVICE bar was cleared — an
   * emailed link plus a public NPI. Approved means a human who can ring the
   * department said yes. Pushing a steward's decision through verify() left the
   * two indistinguishable by status, with only the method column separating "a
   * person vouched for this" from "somebody typed a number anybody can look up".
   *
   * Record keeping: approve() writes decidedBy and decidedAt onto the claim,
   * where verify() does not. "Who vouched for this?" should be answerable from
   * the row rather than by cross-referencing timestamps in audit_logs.
   *
   * Reachable from Pending as well as Verified, deliberately: a steward who
   * recognises the claimant should not have to send them off to find their NPI,
   * and confirming an already self-verified claim is also a legitimate act.
   */
  #[IsGranted('ROLE_DATA_STEWARD')]
  #[Route(
    '/admin/claims/{claim}/approve',
    name: 'hmfp_search_tool_admin_claim_approve',
    requirements: ['claim' => '\d+'],
    methods: ['POST'],
  )]
  public function approve(Entity\PhysicianClaim $claim, Request $request): Response {
    if (!$this->isCsrfTokenValid('approve_claim_' . $claim->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_admin_claims');
    }

    // approve() throws on a claim that is already finished with — see
    // ClaimStatus::isFinal(). Caught here so a typed URL produces a sentence
    // rather than a 500; the template does not offer the button in these states.
    if ($claim->getStatus()->isFinal()) {
      $this->addFlash('error', sprintf(
        'That claim was already %s and cannot be approved. Start a new claim instead.',
        mb_strtolower($claim->getStatus()->label()),
      ));
      return $this->redirectToRoute('hmfp_search_tool_admin_claims');
    }

    if ($claim->getStatus() === ClaimStatus::Approved) {
      $this->addFlash('notice', 'That claim was already approved.');
      return $this->redirectToRoute('hmfp_search_tool_admin_claims');
    }

    $note = trim((string) $request->request->get('note', ''));

    $claim->approve(
      $this->currentUser(),
      $note === '' ? 'Approved by a data steward.' : $note,
      new \DateTimeImmutable(),
    );

    try {
      $this->entityManager->flush();
    }
    catch (UniqueConstraintViolationException) {
      // UNIQ_PHYSCLAIM_ACTIVE fired: this physician already has a live claim, and
      // approving a second one would give two accounts the right to speak as the
      // same person. Reachable because Pending deliberately does NOT take the
      // exclusive slot, so several people may hold pending claims on one record —
      // which is exactly the contested case the extract's 59 colliding name pairs
      // produce. The database refuses; this turns the refusal into instructions.
      //
      // The entity manager is closed after a failed flush, so nothing further may
      // be written here — logToLogger() goes to the channel, not the database.
      $this->auditLogManager->logToLogger('claim.contested', $claim, [
        'physicianId' => $claim->getPhysician()->getId(),
        'claimant'    => $claim->getClaimantLabel(),
      ], \Psr\Log\LogLevel::WARNING);

      $this->addFlash('error', 'Somebody else already holds a live claim on that physician. Withdraw theirs first, then approve this one.');

      return $this->redirectToRoute('hmfp_search_tool_admin_claims');
    }

    $this->auditLogManager->log('claim.approved', $claim, [
      'physicianId' => $claim->getPhysician()->getId(),
      'claimant'    => $claim->getClaimantLabel(),
    ]);

    $this->addFlash('success', 'Claim approved.');

    return $this->redirectToRoute('hmfp_search_tool_admin_claims');
  }

  /**
   * Revoke a claim (hardcorded to ROLE_DATA_STEWARD for now)
   */
  #[IsGranted('ROLE_DATA_STEWARD')]
  #[Route(
    '/admin/claims/{claim}/revoke',
    name: 'hmfp_search_tool_admin_claim_revoke',
    requirements: ['claim' => '\d+'],
    methods: ['POST'],
  )]
  public function revoke(Entity\PhysicianClaim $claim, Request $request): Response {
    if (!$this->isCsrfTokenValid('revoke_claim_' . $claim->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_admin_claims');
    }

    // Checked here as well as in the template, because a URL can be typed and the
    // entity refuses outright: PhysicianClaim::revoke() throws for a claim that
    // was never live, deliberately — a pending claim is REFUSED, not withdrawn,
    // and collapsing the two loses the distinction between "never good" and "was
    // acted on and then taken back", which is where an investigation starts.
    //
    // Without this the steward gets a 500 rather than a sentence.
    if (!$claim->getStatus()->grantsEditing()) {
      $this->addFlash('error', sprintf(
        'That claim is already %s, so there is nothing to withdraw.',
        mb_strtolower($claim->getStatus()->label()),
      ));
      return $this->redirectToRoute('hmfp_search_tool_admin_claims');
    }

    $steward = $this->currentUser();
    $claim->revoke($steward, "Claim revoked by data steward.", new \DateTimeImmutable());
    $this->entityManager->flush();

    $this->auditLogManager->log('claim.revoked', $claim, [
      'physicianId' => $claim->getPhysician()->getId(),
      'claimant'    => $claim->getClaimantLabel(),
    ]);
    $this->addFlash('success', 'Claim revoked successfully.');

    return $this->redirectToRoute('hmfp_search_tool_admin_claims');
  }

  /**
   * Withdraw a claim AND roll back everything it published.
   *
   * Deliberately a SECOND button rather than folding this into revoke(). The two
   * answer different situations:
   *
   *   revoke()  — the claim should no longer exist. Somebody changed department,
   *               left the practice, or the record was merged. Their edits were
   *               made in good faith and are probably still accurate; discarding
   *               a year of correct bios would be vandalism dressed as tidying.
   *
   *   this      — the claimant should never have had the record. Everything they
   *               published here is suspect on principle, whether or not anybody
   *               has read it.
   *
   * Only this person's edits are reverted, not every edit on the physician. A
   * blanket revert would also discard work by stewards and by the rightful
   * claimant before the record changed hands — undoing one person's edits must not
   * mean destroying everybody's.
   *
   * Nothing is deleted. Each edit is REJECTED, which is how the edit layer already
   * expresses a revert: the row survives, so the history still shows what was
   * published, by whom, and for how long. An audit trail that quietly loses the
   * content it is auditing is not one.
   */
  #[IsGranted('ROLE_DATA_STEWARD')]
  #[Route(
    '/admin/claims/{claim}/revoke-and-revert',
    name: 'hmfp_search_tool_admin_claim_revoke_revert',
    requirements: ['claim' => '\d+'],
    methods: ['POST'],
  )]
  public function revokeAndRevert(Entity\PhysicianClaim $claim, Request $request): Response {
    if (!$this->isCsrfTokenValid('revoke_revert_claim_' . $claim->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_admin_claims');
    }

    if (!$claim->getStatus()->grantsEditing()) {
      $this->addFlash('error', sprintf(
        'That claim is already %s, so there is nothing to withdraw.',
        mb_strtolower($claim->getStatus()->label()),
      ));
      return $this->redirectToRoute('hmfp_search_tool_admin_claims');
    }

    $steward   = $this->currentUser();
    $physician = $claim->getPhysician();
    $note      = trim((string) $request->request->get('note', ''));

    $claim->revoke(
      $steward,
      $note === '' ? 'Claim revoked and the claimant\'s edits reverted.' : $note,
      new \DateTimeImmutable(),
    );

    $reverted = 0;

    foreach ($this->edits->findPublishedByAuthorFor($physician, $claim->getUser()) as $edit) {
      $this->editManager->reject($edit, $steward);

      // Flushed inside the loop, which looks wasteful until you read reject(): for
      // a taxonomy field it recomputes the projection from the edits the DATABASE
      // still reports as published, filtering out only the one edit in hand. An
      // unflushed rejection from an earlier pass is therefore still "published" as
      // far as that query is concerned, and the last iteration would happily
      // restore a value this loop had already revoked.
      //
      // One claimant's edits to one physician is a handful of rows, so correctness
      // is the cheaper trade.
      $this->entityManager->flush();
      $reverted++;
    }

    // Flushed again for the claim itself when the loop did nothing.
    $this->entityManager->flush();

    $this->auditLogManager->log('claim.revoked_with_revert', $claim, [
      'physicianId'  => $physician->getId(),
      'claimant'     => $claim->getClaimantLabel(),
      'editsReverted' => $reverted,
    ]);

    $this->addFlash('success', sprintf(
      'Claim withdrawn and %d edit%s reverted.',
      $reverted,
      $reverted === 1 ? '' : 's',
    ));

    return $this->redirectToRoute('hmfp_search_tool_admin_claims');
  }

  /**
   * Refuse a claim that never went live.
   *
   * The counterpart to revoke(), and a genuinely different act rather than a
   * synonym. Rejecting says the claim was never good; revoking says it was acted
   * on and then withdrawn — so anything the claimant edited while they held it is
   * worth a second look. The two must stay distinguishable in the history, which
   * is why there are two endpoints and two statuses instead of one "cancel".
   *
   * Hardcoded to ROLE_DATA_STEWARD to match revoke() for now.
   */
  #[IsGranted('ROLE_DATA_STEWARD')]
  #[Route(
    '/admin/claims/{claim}/reject',
    name: 'hmfp_search_tool_admin_claim_reject',
    requirements: ['claim' => '\d+'],
    methods: ['POST'],
  )]
  public function reject(Entity\PhysicianClaim $claim, Request $request): Response {
    if (!$this->isCsrfTokenValid('reject_claim_' . $claim->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_admin_claims');
    }

    // A claim that already granted editing is revoked, not rejected. Refusing here
    // rather than quietly doing the other thing: a steward who clicked the wrong
    // button should be told, not silently obeyed, because the two leave different
    // stories in the history.
    if ($claim->getStatus()->grantsEditing()) {
      $this->addFlash('error', 'That claim is live — withdraw it instead of refusing it.');
      return $this->redirectToRoute('hmfp_search_tool_admin_claims');
    }

    if ($claim->getStatus()->isFinal()) {
      $this->addFlash('error', sprintf(
        'That claim is already %s.',
        mb_strtolower($claim->getStatus()->label()),
      ));
      return $this->redirectToRoute('hmfp_search_tool_admin_claims');
    }

    $note = trim((string) $request->request->get('note', ''));

    $claim->reject(
      $this->currentUser(),
      $note === '' ? 'Refused by a data steward.' : $note,
      new \DateTimeImmutable(),
    );
    $this->entityManager->flush();

    $this->auditLogManager->log('claim.rejected', $claim, [
      'physicianId' => $claim->getPhysician()->getId(),
      'claimant'    => $claim->getClaimantLabel(),
    ]);

    $this->addFlash('success', 'Claim refused.');

    return $this->redirectToRoute('hmfp_search_tool_admin_claims');
  }

  /**
   * Step 1 — the offer.
   *
   * A GET that changes nothing, so a mis-click, a prefetch or a crawler cannot
   * start a claim. The state change needs the POST below.
   */
  // ROLE_USER rather than CAN_CLAIM_PHYSICIAN, deliberately. This page also
  // reports "you have claimed this" and "somebody else has claimed this", and
  // both of those must stay readable by people who cannot claim — otherwise the
  // one screen that explains why the record is unavailable is itself
  // unavailable. The POST below is where the permission belongs.
  #[IsGranted('ROLE_USER')]
  #[Route(
    '/physicians/{physician}/claim',
    name: 'hmfp_search_tool_claim_start',
    requirements: ['physician' => '\\d+'],
    methods: ['GET'],
  )]
  public function start(Entity\Physician $physician): Response {
    $user = $this->currentUser();

    return $this->render('@HMFPSearchTool/profile/claim.html.twig', [
      'physician' => $physician,

      // Somebody else already holds it. The template shows this instead of the
      // offer, and says who — a physician looking at their own claimed record
      // needs to know whether the holder is them, an assistant, or a stranger.
      'activeClaim' => $this->claims->activeClaimOn($physician),

      // This user has an email already sitting in their inbox. Offer to re-send
      // rather than silently minting a second one.
      'pendingClaim' => $this->claims->pendingClaimFor($physician, $user),

      'supportEmail' => self::SUPPORT_EMAIL,
    ]);
  }

  /**
   * Step 2 — start the claim and send the link.
   */
  #[IsGranted(Interfaces\Security\Voter\PhysicianVoterInterface::PERMISSION_CAN_CLAIM_PHYSICIAN, subject: 'physician')]
  #[Route(
    '/physicians/{physician}/claim',
    name: 'hmfp_search_tool_claim_request',
    requirements: ['physician' => '\\d+'],
    methods: ['POST'],
  )]
  public function request(Entity\Physician $physician, Request $request): Response {
    $user = $this->currentUser();

    if (!$this->isCsrfTokenValid('claim-' . $physician->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_claim_start', ['physician' => $physician->getId()]);
    }

    // Refuse early if the record is already spoken for. The UNIQUE index would
    // catch it at step 4 regardless, but sending someone a confirmation email for
    // a claim that cannot succeed is a poor way to spend their afternoon.
    if ($this->claims->activeClaimOn($physician) !== null) {
      $this->addFlash('error', sprintf(
        'This profile has already been claimed. If that is not right, email %s.',
        self::SUPPORT_EMAIL,
      ));
      return $this->redirectToRoute('hmfp_search_tool_claim_start', ['physician' => $physician->getId()]);
    }

    // Re-use an existing pending claim rather than stacking up a row per click.
    // issueToken() replaces the hash, so the previous link stops working — which
    // is the behaviour you want from "send it again": one live link at a time.
    $claim = $this->claims->pendingClaimFor($physician, $user)
      ?? new Entity\PhysicianClaim($physician, $user);

    $token = $claim->issueToken();

    $this->entityManager->persist($claim);

    // Flushed BEFORE the claim is audited, so that the claim has an id to be
    // audited by. AuditLogManager::describe() reads getId() off its target, and
    // a claim that has not been inserted yet has none — logging first writes a
    // row that names the type but cannot point at the claim it describes, which
    // is exactly what the earlier rows in audit_logs show.
    //
    // The cost is two flushes rather than one. That is the honest price of
    // wanting the audit trail to carry a usable reference.
    $this->entityManager->flush();

    $this->auditLogManager->log('claim.requested', $claim, [
      'physicianId' => $physician->getId(),
      'claimant'    => $claim->getClaimantLabel(),
    ]);

    // Sent AFTER the flush, deliberately. If the insert fails, no email goes out
    // carrying a token that does not exist. The reverse order would hand somebody
    // a link that answers "this link is not valid" and no way to tell why.
    $this->sendConfirmation($claim, $token);

    $this->addFlash('success', sprintf(
      'Check your email. We have sent a confirmation link to %s.',
      $user->getEmailAddress(),
    ));

    return $this->redirectToRoute('hmfp_search_tool_claim_start', ['physician' => $physician->getId()]);
  }

  /**
   * Step 3 — the emailed link. Asks for the NPI.
   *
   * Behind ROLE_USER like everything else, so following the link in a browser
   * that is not signed in lands on the login page and returns here afterwards.
   * That is intentional: the token identifies a CLAIM, it does not authenticate
   * anybody, and it must never be able to stand in for signing in.
   */
  #[IsGranted('ROLE_USER')]
  #[Route(
    '/claims/confirm/{token}',
    name: 'hmfp_search_tool_claim_confirm_form',
    requirements: ['token' => '[0-9a-f]{64}'],
    methods: ['GET'],
  )]
  public function confirmForm(string $token): Response {
    $claim = $this->requireOwnClaim($token);

    return $this->render('@HMFPSearchTool/profile/claim_confirm.html.twig', [
      'claim'         => $claim,
      'physician'     => $claim->getPhysician(),
      'token'         => $token,
      'expired'       => !$claim->matchesToken($token),
      'attemptsLeft'  => Entity\PhysicianClaim::MAX_ATTEMPTS - $claim->getAttempts(),
      'supportEmail'  => self::SUPPORT_EMAIL,
    ]);
  }

  /**
   * Step 4 — check the NPI and write the link.
   */
  #[IsGranted('ROLE_USER')]
  #[Route(
    '/claims/confirm/{token}',
    name: 'hmfp_search_tool_claim_confirm',
    requirements: ['token' => '[0-9a-f]{64}'],
    methods: ['POST'],
  )]
  public function confirm(string $token, Request $request): Response {
    $claim     = $this->requireOwnClaim($token);
    $physician = $claim->getPhysician();

    if (!$this->isCsrfTokenValid('claim-confirm-' . $claim->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_claim_confirm_form', ['token' => $token]);
    }

    // Checked before the NPI, so an expired link says so rather than burning an
    // attempt and reporting a wrong number.
    if (!$claim->matchesToken($token)) {
      $this->addFlash('error', 'That confirmation link has expired. Start the claim again to get a new one.');
      return $this->redirectToRoute('hmfp_search_tool_claim_start', ['physician' => $physician->getId()]);
    }

    if (!$claim->acceptsAttempts()) {
      $this->addFlash('error', sprintf(
        'This claim has had too many incorrect attempts and is now closed. Email %s and a person will help.',
        self::SUPPORT_EMAIL,
      ));
      return $this->redirectToRoute('hmfp_search_tool_claim_start', ['physician' => $physician->getId()]);
    }

    $supplied = trim((string) $request->request->get('npi', ''));

    if (!$claim->matchesNpi($supplied)) {
      $claim->recordFailedAttempt();

      // Logged as its own action, because the interesting pattern is not one
      // wrong number — it is one account working through several.
      $this->auditLogManager->log('claim.attempt_failed', $claim, [
        'physicianId' => $physician->getId(),
        'claimant'    => $claim->getClaimantLabel(),
        'attempts'    => $claim->getAttempts(),
      ], flush: false);

      $this->entityManager->flush();

      $left = Entity\PhysicianClaim::MAX_ATTEMPTS - $claim->getAttempts();

      $this->addFlash('error', sprintf(
        'That NPI does not match this record. %s',
        $left > 0
          ? sprintf('You have %d attempt%s left.', $left, $left === 1 ? '' : 's')
          : 'This claim is now closed.',
      ));

      return $this->redirectToRoute('hmfp_search_tool_claim_confirm_form', ['token' => $token]);
    }

    $claim->verify(ClaimMethod::SelfServiceNpi);

    $this->auditLogManager->log('claim.verified', $claim, [
      'physicianId' => $physician->getId(),
      'claimant'    => $claim->getClaimantLabel(),
      'method'      => ClaimMethod::SelfServiceNpi->value,
    ], flush: false);

    try {
      $this->entityManager->flush();
    }
    catch (UniqueConstraintViolationException) {
      // UNIQ_PHYSCLAIM_ACTIVE fired: somebody else verified a claim on this
      // physician between this request starting and its flush. Not an error to
      // hide — it is the single most informative event this flow produces, and it
      // fires precisely on the colliding-name cases the extract is full of.
      //
      // The entity manager is closed after a failed flush, so this handler cannot
      // write anything more; the channel sink in logToLogger() does not need it.
      $this->auditLogManager->logToLogger('claim.contested', $claim, [
        'physicianId' => $physician->getId(),
        'claimant'    => $claim->getClaimantLabel(),
      ], \Psr\Log\LogLevel::WARNING);

      $this->addFlash('error', sprintf(
        'Someone else has just claimed this profile. If you believe it is yours, email %s — two providers here share a name and a person needs to untangle it.',
        self::SUPPORT_EMAIL,
      ));

      return $this->redirectToRoute('hmfp_search_tool_profile_view', ['id' => $physician->getId()]);
    }

    $this->addFlash('success', 'Thank you — this profile is now linked to your account. You can propose edits to it, and a data steward will review them.');

    return $this->redirectToRoute('hmfp_search_tool_profile_view', ['id' => $physician->getId()]);
  }

  /**
   * Emails the confirmation link.
   *
   * Sent to the address ON THE ACCOUNT, never to one supplied in the request.
   * That is the whole reason this step is worth anything: the account's address
   * came from the identity provider, so it is the one piece of this flow nobody
   * can choose for themselves.
   *
   * Failures are swallowed on purpose. The claim row is already committed, so a
   * transport problem should not present as a failed claim — the claimant can ask
   * for another link, and this way they get a clear "check your email" rather
   * than a stack trace. Logged, so the transport problem is still visible.
   */
  private function sendConfirmation(Entity\PhysicianClaim $claim, string $token): void {
    $user = $claim->getUser();

    try {
      $email = (new TemplatedEmail())
        ->from(self::MAIL_FROM)
        ->to($user->getEmailAddress())
        ->subject('Confirm your HMFP Search Tool provider profile')
        ->htmlTemplate('@HMFPSearchTool/profile/claim_email.html.twig')
        ->context([
          'user'      => $user,
          'physician' => $claim->getPhysician(),
          'expiresAt' => $claim->getTokenExpiresAt(),

          // Absolute: this is going into an email, where a relative path is
          // meaningless.
          'confirmUrl' => $this->urlGenerator->generate(
            'hmfp_search_tool_claim_confirm_form',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
          ),
          'supportEmail' => self::SUPPORT_EMAIL,
        ]);

      if ($this->mailer->send($email)) {
        $this->auditLogManager->log('claim.emailed', $claim, ['physicianId' => $claim->getPhysician()->getId(), 'time' => new \DateTime()->format('Y-m-d H:i:s'), 'user' => $claim->getUser()->getId()], flush: false);
      }

    }
    catch (\Throwable $e) {
      $this->auditLogManager->logToLogger('claim.mail_failed', $claim, [
        'physicianId' => $claim->getPhysician()->getId(),
        'error'       => $e->getMessage(),
      ], \Psr\Log\LogLevel::ERROR);
    }
  }

  /**
   * The claim this token belongs to, if it belongs to the signed-in user.
   *
   * The ownership check is the important line. Without it, anybody holding a
   * token could confirm a claim made by somebody else — the token would become
   * the only thing standing between an account and a link it never requested.
   *
   * A mismatch is a 404 rather than a 403, so the response cannot be used to
   * establish that a given token exists at all.
   */
  private function requireOwnClaim(string $token): Entity\PhysicianClaim {
    $claim = $this->claims->findByToken($token);

    if ($claim === null || $claim->getUser()->getId() !== $this->currentUser()->getId()) {
      throw $this->createNotFoundException('That confirmation link is not valid.');
    }

    return $claim;
  }

  private function requirePhysician(int $id): Entity\Physician {
    $physician = $this->physicians->find($id);

    if ($physician === null) {
      throw $this->createNotFoundException('Physician not found.');
    }

    return $physician;
  }

  private function currentUser(): Entity\User {
    $user = $this->getUser();

    if (!$user instanceof Entity\User) {
      throw $this->createAccessDeniedException('Not signed in.');
    }

    return $user;
  }
}
