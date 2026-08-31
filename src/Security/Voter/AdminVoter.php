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

class AdminVoter extends PixieHelperSecurity\Voter\BaseVoter implements Interfaces\Security\Voter\AdminVoterInterface {

  protected function supports(string $attribute, mixed $subject): bool {
    $attributes = $this->getAttributes();
    return in_array($attribute, $attributes);
  }

  protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool {
    $user = $token->getUser();

    if (!$user instanceof UserInterface) {
      return false;
    }

    $adminRoles = $this->getAdminRoles();

    return match($attribute) {
      // @see: \Pixiekat\SymfonyHelpers\Interfaces\Security\Voter\AdminVoterInterface
      self::ADMIN_ADMINISTER => $this->hasAtLeastOneAdminRole($user, $adminRoles),
      self::PERMISSION_CAN_ACCESS_ADMIN => $this->hasAtLeastOneAdminRole($user, $adminRoles),
      // App specific permissions.
      // @see: \Pixiekat\HMFPSearchToolBundle\Interfaces\Security\Voter\AdminVoterInterface
      self::PERMISSION_CAN_ACCESS_REPORTS => $this->hasGlobalAdminRole($user, $adminRoles) || $this->security->isGranted(Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_ANALYTICS_VIEWER),
      self::PERMISSION_CAN_MANAGE_USERS => $this->hasGlobalAdminRole($user, $adminRoles) || $this->security->isGranted(Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_DATA_STEWARD),
      self::PERMISSION_CAN_APPROVE_EDITS => $this->hasGlobalAdminRole($user, $adminRoles) || $this->security->isGranted(Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_DATA_STEWARD),
      default => false,
    };
  }

  private function getAdminRoles(): array {
    return [
      'ROLE_SUPER_ADMIN',
      Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_SYSADMIN,
      Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_ADMIN,
      Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_CONTENT_ADMIN,
      Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_DATA_STEWARD,
      Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_DEPARTMENT_EDITOR,
      Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_ANALYTICS_VIEWER,
    ];
  }
}
