<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Security\Voter;

use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Interfaces;
use Pixiekat\SymfonyHelpers\Security as PixieHelperSecurity;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

class SearchVoter extends PixieHelperSecurity\Voter\BaseVoter implements Interfaces\Security\Voter\SearchVoterInterface {

  protected function supports(string $attribute, mixed $subject): bool {
    $attributes = $this->getAttributes();
    return in_array($attribute, $attributes);
  }

  /**
   * Decides a search-area permission for the current token.
   *
   * @param string $attribute
   *   One of the SearchVoterInterface::PERMISSION_* constants.
   * @param mixed $subject
   *   Unused for these attributes.
   * @param \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token
   *   The current token; a NullToken for logged-out visitors.
   * @param \Symfony\Component\Security\Core\Authorization\Voter\Vote|null $vote
   *   Optional vote object for explanations (Symfony 7.3+).
   *
   * @return bool
   *   TRUE to grant, FALSE to deny.
   */
  protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool {
    // Attributes that do NOT require a signed-in user are decided first.
    // Logged-out visitors arrive with a NullToken whose getUser() is null, so
    // anything below the user guard is unreachable for them — which is how
    // search kept bouncing anonymous visitors to /login. Search is open to
    // everyone; logging out (and anything added later) stays behind the guard.
    //
    // Viewing a physician is deliberately NOT decided here: PhysicianVoter is
    // the single authority for can_view_physician. See SearchVoterInterface.
    if ($attribute === self::PERMISSION_CAN_ACCESS_SEARCH) {
      return $this->canAccessSearch();
    }

    $user = $token->getUser();

    if (!$user instanceof UserInterface) {
      return false;
    }

    $forceForAdmins = [];

    if ($this->isSysAdmin() && !in_array($attribute, $forceForAdmins)) {
      return true;
    }

    // PERMISSION_CAN_ACCESS_SEARCH is handled above the user guard, so it has
    // no arm here. `default` makes this match exhaustive, so no trailing
    // return is needed.
    return match($attribute) {
        // a user can log out if they are logged in and active. If they are not active, they should not be able to log out because they should not be logged in at all.
        self::PERMISSION_CAN_LOGOUT => $this->security->isGranted('IS_AUTHENTICATED_FULLY') && $this->canLogIn($user),
        default => false,
    };
  }

  private function canLogIn(UserInterface $user): bool {
    if (!$user instanceof Entity\User) {
      return false;
    }

    // check if the user is active
    if (!$user->isActive()) {
      return false;
    }

    // in the future, we may want to add more checks here, such as checking if the user has 2FA enabled and if they have completed the 2FA process. For now, we will just check if the user is active.

    return true;
  }

  /**
   * Whether the current visitor may use search.
   *
   * Open to everyone, signed in or not; editing, claiming, etc. remain
   * authenticated-only through their own voters. Kept as a method rather than
   * inlined so a future restriction (feature flag, network check, rate-limit
   * gate) has one obvious home.
   *
   * The previous body, `IS_AUTHENTICATED_FULLY || PUBLIC_ACCESS`, was always
   * TRUE because PUBLIC_ACCESS always grants; this states that plainly.
   *
   * @return bool
   *   Always TRUE at present.
   */
  public function canAccessSearch(): bool {
    return true;
  }
}
