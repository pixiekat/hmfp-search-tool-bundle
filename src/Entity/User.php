<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Pixiekat\HMFPSearchToolBundle\Interfaces as HMFPSearchToolInterfaces;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Application user.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
#[ORM\RepositoryClass('Pixiekat\HMFPSearchToolBundle\Repository\UserRepository')]
class User implements HelpersUserInterface, UserInterface, PasswordAuthenticatedUserInterface {
  use PixieTraits\EntityIdTrait;

  #[ORM\Column(type: 'string', length: 255, unique: true)]
  private string $emailAddress;

  use PixieTraits\EntityPasswordTrait;
  use PixieTraits\EntityRolesTrait;
  use PixieTraits\EntityActiveTrait;
  use PixieTraits\EntityCreatedAtTrait;

  public function __construct() {
    $this->setActive(true);
    $this->setCreatedAt(new \DateTimeImmutable());
  }

  /** No transient/plaintext secrets are held on the entity, so nothing to erase. */
  public function eraseCredentials(): void {
  }

  /** Maps the display name for the user. */
  public function getDisplayName(): string {
    return $this->emailAddress;
  }

  /** The email address of the user. */
  public function getEmailAddress(): string {
    return $this->emailAddress;
  }

  /** The unique identifier Symfony Security uses for this user. */
  public function getUserIdentifier(): string {
    return (string) $this->emailAddress;
  }

  /** The unique identifier Symfony Security uses for this user. */
  public function setEmailAddress(string $emailAddress): self {
    $this->emailAddress = $emailAddress;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function getAllRoles(bool $includeUser = true, bool $invert = false): array {
    $roles = [
      HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_SYSADMIN => HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_SYSADMIN_LABEL,
      HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_ADMIN => HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_ADMIN_LABEL,
      HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_CONTENT_ADMIN => HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_CONTENT_ADMIN_LABEL,
      HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_DATA_STEWARD => HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_DATA_STEWARD_LABEL,
      HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_DEPARTMENT_EDITOR => HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_DEPARTMENT_EDITOR_LABEL,
      HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_ANALYTICS_VIEWER => HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_ANALYTICS_VIEWER_LABEL,
    ];

    if ($includeUser) {
      $roles[HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_USER] = HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_USER_LABEL;
    }

    if ($invert) {
      $roles = array_flip($roles);
    }

    return $roles;
  }

  public function isSysAdmin(): bool {
    $sysAdminRoles = [
      HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_SYSADMIN,
    ];
    return in_array(HMFPSearchToolInterfaces\Entity\HMFPSearchToolUserInterface::ROLE_SYSADMIN, $this->getRoles());
  }

  public function isFirstUser(): bool {
    return $this->getId() === 1;
  }
}
