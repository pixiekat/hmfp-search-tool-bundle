<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Security;

use Pixiekat\HMFPSearchToolBundle\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses to sign in an account that is not active.
 *
 * Before this existed, users.is_active was consulted by the voters but not by
 * the firewall — so a deactivated account could still sign in, and was only
 * stopped later, permission by permission, wherever a voter happened to check.
 * Delegate invitations make that gap matter more: an invited person's account
 * exists, inactive, before they have agreed to anything, and "inactive" has to
 * mean "cannot sign in" for that to be safe.
 *
 * Pre-auth, so the refusal comes BEFORE the password is checked. That means a
 * deactivated account learns it is deactivated rather than being told its
 * password is wrong — honest, and it saves a support call — at the cost of
 * revealing that the address has an account. Inside a staff tool whose
 * accounts come from the organisation's own directory, that is the right trade.
 *
 * Wired in the app's security.yaml (`user_checker:` on the main firewall).
 *
 * ── When Entra SSO arrives ────────────────────────────────────────────────
 * The SSO authenticator should run the same checker (Symfony does this for
 * every authenticator on the firewall). An invited-but-not-yet-activated
 * account is the one case it has to handle first: signing in with Microsoft IS
 * that person's activation, so the authenticator should activate the account
 * before this checker sees it — see DelegationManager::activateAccount().
 */
final class UserChecker implements UserCheckerInterface {

  public function checkPreAuth(UserInterface $user): void {
    if (!$user instanceof User) {
      return;
    }

    if ($user->isInactive()) {
      // Custom*AccountStatus* so the login page shows this sentence rather than
      // the generic "Invalid credentials." — see the note above.
      throw new CustomUserMessageAccountStatusException(
        'This account is not active. If you were invited to help with a provider profile, use the link in your invitation email to set it up.',
      );
    }
  }

  public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void {
  }
}
