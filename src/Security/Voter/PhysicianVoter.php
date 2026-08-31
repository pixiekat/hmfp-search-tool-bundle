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

class PhysicianVoter extends PixieHelperSecurity\Voter\BaseVoter implements Interfaces\Security\Voter\PhysicianVoterInterface {

  protected function supports(string $attribute, mixed $subject): bool {
    $attributes = $this->getAttributes();
    return in_array($attribute, $attributes);
  }

  protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool {
    $user = $token->getUser();

    if (!$user instanceof UserInterface) {
      return false;
    }

    $forceForAdmins = [];

    if ($this->isSysAdmin() && !in_array($attribute, $forceForAdmins)) {
      return true;
    }

    return match($attribute) {
        self::PERMISSION_CAN_EDIT_PHYSICIAN => $this->security->isGranted('ROLE_USER'),
        self::PERMISSION_CAN_VIEW_PHYSICIAN_EDITS => $this->security->isGranted('ROLE_USER'),
        self::PERMISSION_CAN_VIEW_ALL_PHYSICIAN_EDITS => $this->canViewAllPhysicianEdits($user),
        self::PERMISSION_CAN_APPROVE_PHYSICIAN_EDITS => $this->canApprovePhysicianEdits($user),
        default => false,
    };

    return false;
  }

  private function canApprovePhysicianEdits(UserInterface $user): bool {
    if (!$user instanceof Entity\User) {
      return false;
    }

    // user is ROLE_ADMIN or ROLE_DATA_STEWARD. Named through the interface: this
    // line already spelled it ROLE_DATA_STEWARD while the stored role was
    // DATA_STEWARD, so it silently matched nobody until the rename.
    return $this->security->isGranted(Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_ADMIN)
      || $this->security->isGranted(Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_DATA_STEWARD);
  }

  /**
   * Whether or not a user can view all historical physician edits.
   *   For now, use self::canApprovePhysicianEdits().
   *
   * @param UserInterface $user
   * @return boolean
   */
  private function canViewAllPhysicianEdits(UserInterface $user): bool {
    return $this->canApprovePhysicianEdits($user);
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
}
