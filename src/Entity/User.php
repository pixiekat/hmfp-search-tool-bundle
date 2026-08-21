<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
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
}
