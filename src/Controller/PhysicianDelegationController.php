<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Controller;

use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Exception\DelegationRefusedException;
use Pixiekat\HMFPSearchToolBundle\Interfaces;
use Pixiekat\HMFPSearchToolBundle\Repository;
use Pixiekat\HMFPSearchToolBundle\Services;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Delegates: people a claimant allows to propose edits on their behalf.
 *
 * Three audiences, three sets of routes:
 *
 *   The claimant
 *     GET  /physicians/{id}/delegates                  who has access; invite form
 *     POST /physicians/{id}/delegates                  invite (or re-send)
 *     POST /physicians/{id}/delegates/{d}/remove       remove / withdraw
 *
 *   The invitee — from the emailed link
 *     GET  /delegations/respond/{token}                set up account, or accept/decline
 *     POST /delegations/respond/{token}/accept
 *     POST /delegations/respond/{token}/decline
 *
 *   A data steward
 *     POST /admin/delegations/{d}/revoke               remove anybody's delegate
 *
 * ── Why the invitee's routes are not behind ROLE_USER ──────────────────────
 * Somebody invited at an address with no account cannot sign in — that is the
 * point of the link. So those routes are public, and each one decides for
 * itself:
 *
 *   - invitation that activates an account → the TOKEN is the credential, the
 *     same way a password-reset link is, and it is spent on first use;
 *   - invitation to an existing account → the token only identifies the
 *     invitation, and the person must be SIGNED IN AS THE INVITEE to answer it
 *     (the claim flow's rule: a token never stands in for signing in).
 *
 * No JavaScript anywhere; every step is a form post with a CSRF token.
 */
final class PhysicianDelegationController extends AbstractController {

  public function __construct(
    private readonly Repository\PhysicianClaimRepository $claims,
    private readonly Repository\PhysicianDelegationRepository $delegations,
    private readonly Services\DelegationManager $delegationManager,
    private readonly Services\EmailDomainPolicy $domainPolicy,
  ) {  }

  // ── The claimant ──────────────────────────────────────────────────────────

  #[IsGranted(Interfaces\Security\Voter\PhysicianVoterInterface::PERMISSION_CAN_MANAGE_DELEGATES, subject: 'physician')]
  #[Route(
    '/physicians/{physician}/delegates',
    name: 'hmfp_search_tool_delegates',
    requirements: ['physician' => '\d+'],
    methods: ['GET'],
  )]
  public function manage(Entity\Physician $physician): Response {
    $claim = $this->requireLiveClaim($physician);

    return $this->render('@HMFPSearchTool/delegation/manage.html.twig', [
      'physician'      => $physician,
      'claim'          => $claim,
      'delegations'    => $this->delegations->findForClaim($claim),
      'openCount'      => $this->delegations->countOpenForClaim($claim),
      'maxOpen'        => Entity\PhysicianDelegation::MAX_OPEN_PER_CLAIM,
      'allowedDomains' => $this->domainPolicy->listedDomains(),
      'supportEmail'   => PhysicianClaimController::SUPPORT_EMAIL,
    ]);
  }

  #[IsGranted(Interfaces\Security\Voter\PhysicianVoterInterface::PERMISSION_CAN_MANAGE_DELEGATES, subject: 'physician')]
  #[Route(
    '/physicians/{physician}/delegates',
    name: 'hmfp_search_tool_delegate_invite',
    requirements: ['physician' => '\d+'],
    methods: ['POST'],
  )]
  public function invite(Entity\Physician $physician, Request $request): Response {
    $back = $this->redirectToRoute('hmfp_search_tool_delegates', ['physician' => $physician->getId()]);

    if (!$this->isCsrfTokenValid('delegate-invite-' . $physician->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $back;
    }

    $email = (string) $request->request->get('email', '');

    try {
      $delegation = $this->delegationManager->invite($this->requireLiveClaim($physician), $email, $this->currentUser());
    }
    catch (DelegationRefusedException $e) {
      $this->addFlash('error', $e->getMessage());
      return $back;
    }

    // The same sentence whether an account was created, found, or re-invited —
    // see DelegationManager::invite(). Says what happens next so the claimant
    // can tell the person to expect it.
    $this->addFlash('success', sprintf(
      'Invitation sent to %s. The link in it works for seven days, and they can accept or decline.',
      $delegation->getDelegateLabel(),
    ));

    return $back;
  }

  #[IsGranted(Interfaces\Security\Voter\PhysicianVoterInterface::PERMISSION_CAN_MANAGE_DELEGATES, subject: 'physician')]
  #[Route(
    '/physicians/{physician}/delegates/{delegation}/remove',
    name: 'hmfp_search_tool_delegate_remove',
    requirements: ['physician' => '\d+', 'delegation' => '\d+'],
    methods: ['POST'],
  )]
  public function remove(Entity\Physician $physician, Entity\PhysicianDelegation $delegation, Request $request): Response {
    $back  = $this->redirectToRoute('hmfp_search_tool_delegates', ['physician' => $physician->getId()]);
    $claim = $this->requireLiveClaim($physician);

    // The voter checked that this user holds $physician. This checks that
    // $delegation belongs to it — without this line the claimant of ONE profile
    // could remove delegates from ANY profile by changing the id in the URL.
    // 404 rather than 403, so the URL cannot be used to probe other ids.
    if ($delegation->getClaim()->getId() !== $claim->getId()) {
      throw $this->createNotFoundException('Delegate not found.');
    }

    if (!$this->isCsrfTokenValid('delegate-remove-' . $delegation->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $back;
    }

    if (!$delegation->getStatus()->isOpen()) {
      $this->addFlash('notice', sprintf('%s has already been removed or declined.', $delegation->getDelegateLabel()));
      return $back;
    }

    $wasPending = $delegation->getStatus()->isPending();

    $this->delegationManager->revoke($delegation, $this->currentUser(), $wasPending
      ? 'Invitation withdrawn by the claimant.'
      : 'Removed by the claimant.');

    $this->addFlash('success', $wasPending
      ? sprintf('Invitation to %s withdrawn. The link in their email no longer works.', $delegation->getDelegateLabel())
      : sprintf('%s can no longer edit this profile. Anything they already submitted stays as it is.', $delegation->getDelegateLabel()));

    return $back;
  }

  // ── The invitee ───────────────────────────────────────────────────────────

  /**
   * The emailed link.
   *
   * Public — see the class docblock. Picks one of three pages:
   *   expired   → the link ran out; ask the claimant to re-send
   *   activate  → a new account: choose a password, then accept or decline
   *   respond   → an existing account: signed in as the invitee, accept or decline
   */
  #[Route(
    '/delegations/respond/{token}',
    name: 'hmfp_search_tool_delegation_respond_form',
    requirements: ['token' => '[0-9a-f]{64}'],
    methods: ['GET'],
  )]
  public function respondForm(string $token): Response {
    $delegation = $this->findInvitation($token);

    if (!$delegation->matchesToken($token)) {
      // Before any sign-in check, deliberately: somebody whose link has run out
      // should be told THAT, not bounced through a login page first only to be
      // told it afterwards.
      return $this->renderRespond($delegation, $token, 'expired');
    }

    if ($delegation->canActivateAccount()) {
      // Entra SSO: this is where a signed-out new invitee would be sent through
      // Microsoft sign-in instead of shown a password form. See
      // DelegationManager::activateAccount().
      return $this->renderRespond($delegation, $token, 'activate');
    }

    $this->requireSignedInAsDelegate($delegation);

    return $this->renderRespond($delegation, $token, 'respond');
  }

  #[Route(
    '/delegations/respond/{token}/accept',
    name: 'hmfp_search_tool_delegation_accept',
    requirements: ['token' => '[0-9a-f]{64}'],
    methods: ['POST'],
  )]
  public function accept(string $token, Request $request): Response {
    $delegation = $this->findInvitation($token);
    $back       = $this->redirectToRoute('hmfp_search_tool_delegation_respond_form', ['token' => $token]);

    if (!$delegation->matchesToken($token)) {
      return $back;   // The form page explains the expiry.
    }

    $activating = $delegation->canActivateAccount();

    if (!$activating) {
      $this->requireSignedInAsDelegate($delegation);
    }

    if (!$this->isCsrfTokenValid('delegation-respond-' . $delegation->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $back;
    }

    try {
      $this->delegationManager->accept(
        $delegation,
        $activating ? (string) $request->request->get('password', '') : null,
        $activating ? (string) $request->request->get('password_confirm', '') : null,
      );
    }
    catch (DelegationRefusedException $e) {
      // The password is NOT echoed back into the form. Making somebody type it
      // twice more is a small cost; a password sitting in a rendered page, the
      // browser's form cache and possibly a proxy log is not.
      $this->addFlash('error', $e->getMessage());
      return $back;
    }

    if ($activating) {
      // Not signed in automatically. Sending them through the ordinary login
      // means the two-factor step runs as it does for everybody, and they find
      // out straight away whether the password they just chose is the one they
      // think it is.
      $this->addFlash('success', sprintf(
        'Your account is ready. Sign in with %s and the password you just chose — you will then be able to propose edits to %s\'s profile.',
        $delegation->getDelegate()->getEmailAddress(),
        $delegation->getPhysician()->getLegalName(),
      ));

      return $this->redirectToRoute('pixiekat_symfony_helpers_login');
    }

    $this->addFlash('success', sprintf(
      'Thank you — you can now propose edits to this profile on %s\'s behalf. A data steward reviews every change.',
      $delegation->getInviterLabel(),
    ));

    return $this->redirectToRoute('hmfp_search_tool_profile_view', ['id' => $delegation->getPhysician()->getId()]);
  }

  #[Route(
    '/delegations/respond/{token}/decline',
    name: 'hmfp_search_tool_delegation_decline',
    requirements: ['token' => '[0-9a-f]{64}'],
    methods: ['POST'],
  )]
  public function decline(string $token, Request $request): Response {
    $delegation = $this->findInvitation($token);
    $back       = $this->redirectToRoute('hmfp_search_tool_delegation_respond_form', ['token' => $token]);

    if (!$delegation->matchesToken($token)) {
      return $back;
    }

    // Declining a new-account invitation needs no sign-in (they have no way to
    // sign in yet); declining one to an existing account does, for the same
    // reason accepting does — the token alone must not answer for somebody.
    if (!$delegation->canActivateAccount()) {
      $this->requireSignedInAsDelegate($delegation);
    }

    if (!$this->isCsrfTokenValid('delegation-respond-' . $delegation->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $back;
    }

    $this->delegationManager->decline($delegation);

    $this->addFlash('success', sprintf(
      'You have declined. %s will see that on their delegates page. Nothing else will be sent to you about this.',
      $delegation->getInviterLabel(),
    ));

    return $this->redirectToRoute('<front>');
  }

  // ── A data steward ────────────────────────────────────────────────────────

  /**
   * A steward removes somebody's delegate.
   *
   * Hard-coded to ROLE_DATA_STEWARD to match the claim revoke/reject actions.
   * Returns to the claim's admin screen, which is where the button lives.
   */
  #[IsGranted('ROLE_DATA_STEWARD')]
  #[Route(
    '/admin/delegations/{delegation}/revoke',
    name: 'hmfp_search_tool_admin_delegation_revoke',
    requirements: ['delegation' => '\d+'],
    methods: ['POST'],
  )]
  public function stewardRevoke(Entity\PhysicianDelegation $delegation, Request $request): Response {
    $back = $this->redirectToRoute('hmfp_search_tool_admin_claim_edit', ['id' => $delegation->getClaim()->getId()]);

    if (!$this->isCsrfTokenValid('revoke_delegation_' . $delegation->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $back;
    }

    if (!$delegation->getStatus()->isOpen()) {
      $this->addFlash('notice', 'That delegate has already been removed or declined.');
      return $back;
    }

    $note = trim((string) $request->request->get('note', ''));

    $this->delegationManager->revoke($delegation, $this->currentUser(), $note === '' ? 'Removed by a data steward.' : $note);

    $this->addFlash('success', sprintf('%s is no longer a delegate for this profile.', $delegation->getDelegateLabel()));

    return $back;
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  private function renderRespond(Entity\PhysicianDelegation $delegation, string $token, string $mode): Response {
    return $this->render('@HMFPSearchTool/delegation/respond.html.twig', [
      'delegation'   => $delegation,
      'physician'    => $delegation->getPhysician(),
      'token'        => $token,
      'mode'         => $mode,
      'minPassword'  => Services\DelegationManager::MIN_PASSWORD_LENGTH,
      'supportEmail' => PhysicianClaimController::SUPPORT_EMAIL,
    ]);
  }

  /**
   * The delegation behind a link, or a 404.
   *
   * A spent link (accepted, declined, withdrawn) finds nothing — its hash was
   * cleared — and gets the same "not valid" as a made-up one. That is a little
   * less friendly than "you already accepted this", but it means the response
   * never confirms that a given token ever existed.
   */
  private function findInvitation(string $token): Entity\PhysicianDelegation {
    return $this->delegations->findByToken($token)
      ?? throw $this->createNotFoundException('That invitation link is not valid. It may already have been used.');
  }

  /**
   * Signed in, and as the person invited.
   *
   * Signed out: denyAccessUnlessGranted() hands over to the firewall's entry
   * point, which remembers this URL and sends them to the login page — so the
   * link works straight after signing in. (GET only; Symfony does not remember
   * a POST, which is fine, because the form is on the GET.)
   *
   * Signed in as somebody else: 404, for requireOwnClaim()'s reason in the claim
   * flow — a mismatch must not confirm that the token is real.
   */
  private function requireSignedInAsDelegate(Entity\PhysicianDelegation $delegation): void {
    $this->denyAccessUnlessGranted('ROLE_USER');

    if ($delegation->getDelegate()->getId() !== $this->currentUser()->getId()) {
      throw $this->createNotFoundException('That invitation link is not valid.');
    }
  }

  /**
   * The claimant's live claim on $physician.
   *
   * The voter has already established there is one; this fetches it, and turns
   * the race where it was revoked between the vote and here into a 404 rather
   * than a null dereference.
   */
  private function requireLiveClaim(Entity\Physician $physician): Entity\PhysicianClaim {
    $claim = $this->claims->activeClaimOn($physician);

    if ($claim === null || $claim->getUser()->getId() !== $this->currentUser()->getId()) {
      throw $this->createNotFoundException('You do not hold this profile.');
    }

    return $claim;
  }

  private function currentUser(): Entity\User {
    $user = $this->getUser();

    if (!$user instanceof Entity\User) {
      throw $this->createAccessDeniedException('Not signed in.');
    }

    return $user;
  }
}
