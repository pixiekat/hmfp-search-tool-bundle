<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;

/**
 * A site where physicians practise — a hospital, clinic or practice group.
 */
#[ORM\Entity]
#[ORM\Table(name: 'facilities')]
#[ORM\RepositoryClass('Pixiekat\HMFPSearchToolBundle\Repository\FacilityRepository')]
class Facility {
  use PixieTraits\EntityIdTrait;

  #[ORM\Column(type: 'string', length: 255, nullable: false, unique: true)]
  private string $name;

  /**
   * The physicians practising at this facility.
   *
   * The INVERSE side — note `mappedBy` rather than a JoinTable. Doctrine does
   * not consult this collection when deciding what to write to
   * physician_facilities; use Physician::addFacility() when you want a change
   * to actually persist. See the extended note on Physician::$departments,
   * which explains the owning/inverse rule in full.
   *
   * @var Collection<int, Physician>
   */
  #[ORM\ManyToMany(targetEntity: Physician::class, mappedBy: 'facilities')]
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

  /**
   * Records a physician on this side of the relationship.
   *
   * Deliberately does NOT call back into Physician::addFacility(). The two
   * helpers call each other, and if both delegated unconditionally they would
   * recurse forever; the owning side breaks the cycle because its contains()
   * check runs first. Call Physician::addFacility() as the entry point and both
   * collections end up correct — call this one directly and the link will not
   * be written on flush.
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
