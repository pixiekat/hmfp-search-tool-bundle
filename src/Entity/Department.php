<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Pixiekat\HMFPSearchToolBundle\Interfaces as HMFPSearchToolInterfaces;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;

/**
 * A department within a facility, e.g. "Cardiology" or "Emergency Medicine".
 */
#[ORM\Entity]
#[ORM\Table(name: 'departments')]
#[ORM\RepositoryClass('Pixiekat\HMFPSearchToolBundle\Repository\DepartmentRepository')]
class Department  {
  use PixieTraits\EntityIdTrait;

  #[ORM\Column(type: 'string', length: 255, nullable: false, unique: true)]
  private string $name;

  /**
   * The department's code in the MD staff system.
   *
   * Nullable, because the provider demographics extract does not carry it.
   */
  #[ORM\Column(type: 'string', length: 255, nullable: true)]
  private ?string $MdStaffCode = null;

  /**
   * The physicians practicing in this department.
   *
   * @var Collection<int, Physician>
   */
  #[ORM\ManyToMany(targetEntity: Physician::class, mappedBy: 'departments')]
  private Collection $physicians;

  use PixieTraits\EntityUpdatedAtTrait;
  use PixieTraits\EntityCreatedAtTrait;

  public function __construct() {
    $this->setCreatedAt(new \DateTimeImmutable());
    $this->physicians = new ArrayCollection();
  }

  public function getName(): string {
    return $this->name;
  }

  public function getMdStaffCode(): ?string {
    return $this->MdStaffCode;
  }

  /**
   * @return Collection<int, Physician>
   */
  public function getPhysicians(): Collection {
    return $this->physicians;
  }

  public function setName(?string $name): self {
    $this->name = $name;
    return $this;
  }

  public function setMdStaffCode(?string $MdStaffCode): self {
    $this->MdStaffCode = $MdStaffCode;
    return $this;
  }

  /**
   * Records a physician on this side of the relationship.
   */
  public function addPhysician(Physician $physician): self {
    if (!$this->physicians->contains($physician)) {
      $this->physicians->add($physician);
    }
    return $this;
  }

  public function removePhysician(Physician $physician): self {
    $this->physicians->removeElement($physician);
    return $this;
  }
}
