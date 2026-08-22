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
   * Nullable, because the provider demographics extract does not carry it. The
   * importer creates departments from names alone and leaves this empty rather
   * than inventing a value: a fabricated code is indistinguishable from a real
   * one the moment it is written, and this column exists to point at an
   * external system of record. An empty cell in the admin list is a visible
   * "still to do"; a plausible-looking wrong code is not.
   */
  #[ORM\Column(type: 'string', length: 255, nullable: true)]
  private ?string $MdStaffCode = null;

  /**
   * The physicians practising in this department.
   *
   * The INVERSE side of the relationship — note `mappedBy` rather than a
   * JoinTable. Doctrine does not consult this collection when deciding what to
   * write to physician_departments; see the extended note on
   * Physician::$departments for why, and prefer Physician::addDepartment() when
   * you want a change to actually persist.
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
   *
   * Intentionally does NOT call back into Physician::addDepartment(). The two
   * helpers call each other, and if both delegated unconditionally they would
   * recurse forever; the owning side is where the cycle is broken, because it
   * is the side whose contains() check runs first. Call
   * Physician::addDepartment() as the entry point and both collections end up
   * correct — call this one directly and the link will not be written on flush.
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
