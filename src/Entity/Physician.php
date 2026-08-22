<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Pixiekat\HMFPSearchToolBundle\Interfaces as HMFPSearchToolInterfaces;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;

/**
 * Application user.
 */
#[ORM\Entity]
#[ORM\Table(name: 'physicians')]
#[ORM\RepositoryClass('Pixiekat\HMFPSearchToolBundle\Repository\PhysicianRepository')]
class Physician  {
  use PixieTraits\EntityIdTrait;

  /**
   * The provider's credentialing UUID, as issued by the upstream system.
   *
   * This is NECESSARY for the importer to work correctly and allow for both
   * updates and inserts. It's the only stable identity that we receive from
   * the provider extract so without it we cannot tell if a row is an insert
   * or an update. It remains nullable for backwards compatibility with
   * hand-created rows and pre-existing rows
   *
   * Length 36 is a canonical hyphenated UUID: 8-4-4-4-12.
   */
  #[ORM\Column(name: 'cred_id', type: 'string', length: 36, nullable: true, unique: true)]
  private ?string $credId = null;

  #[ORM\Column(type: 'string', length: 255)]
  private string $legalName;

  #[ORM\Column(type: 'string', length: 255)]
  private string $credentials;


  /**
   * When this physician was last seen in a provider demographics import.
   *
   * Used to identify physicians who have departed or are otherwise no longer
   * being sent in the extracted file. Instead of deleting them, the importer
   * simply stamps this column with the timestamp of the import run. Any physician
   * whose lastSeenInImportAt is older than the most recent import run is a
   * departed physician. This allows the system to keep a record of departed
   * physicians without losing any of the data associated with them.
   *
   * NULL values are used to indicate physicians that were never seen in an
   * import, and are not considered departed. This allows for hand-created physicians
   * to be distinguished from those that were imported and have since departed.
   *
   * @see \Pixiekat\HMFPSearchToolBundle\Repository\PhysicianRepository::countDepartedSince()
   */
  #[ORM\Column(name: 'last_seen_in_import_at', type: 'datetime_immutable', nullable: true)]
  private ?\DateTimeImmutable $lastSeenInImportAt = null;

  #[ORM\Column(type: 'text', nullable: true)]
  private ?string $bio = null;

  /**
   * The departments this physician practises in.
   *
   * A physician can practise in multiple departments, and a department can
   * have multiple physicians. This is a many-to-many relationship, and is
   * represented by the physician_departments join table.
   *
   * Not cascaded to protect against accidental creation or deletion of
   * departments when a physician is created or deleted. The importer
   * persists departments explicitly instead.
   *
   * @var Collection<int, Department>
   */
  #[ORM\ManyToMany(targetEntity: Department::class, inversedBy: 'physicians')]
  #[ORM\JoinTable(name: 'physician_departments')]
  #[ORM\JoinColumn(name: 'physician_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
  #[ORM\InverseJoinColumn(name: 'department_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
  private Collection $departments;

  /**
   * The facilities this physician practises at.
   *
   * A physician can practise at multiple facilities, and a facility can have
   * multiple physicians. This is a many-to-many relationship, and is represented
   * by the physician_facilities join table.
   *
   * @var Collection<int, Facility>
   */
  #[ORM\ManyToMany(targetEntity: Facility::class, inversedBy: 'physicians')]
  #[ORM\JoinTable(name: 'physician_facilities')]
  #[ORM\JoinColumn(name: 'physician_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
  #[ORM\InverseJoinColumn(name: 'facility_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
  private Collection $facilities;

  use PixieTraits\EntityUpdatedAtTrait;

  public function __construct() {
    $this->setUpdatedAt(new \DateTimeImmutable());
    $this->departments = new ArrayCollection();
    $this->facilities = new ArrayCollection();
  }

  public function getBio(): ?string {
    return $this->bio;
  }

  public function getCredId(): ?string {
    return $this->credId;
  }

  public function getLastSeenInImportAt(): ?\DateTimeImmutable {
    return $this->lastSeenInImportAt;
  }

  public function setCredId(?string $credId): self {
    $this->credId = $credId;
    return $this;
  }

  public function setLastSeenInImportAt(?\DateTimeImmutable $lastSeenInImportAt): self {
    $this->lastSeenInImportAt = $lastSeenInImportAt;
    return $this;
  }

  /**
   * @return Collection<int, Department>
   */
  public function getDepartments(): Collection {
    return $this->departments;
  }

  /**
   * Links this physician to a department, both in memory and on flush.
   *
   * The contains() guard is not optional. The join table has a composite
   * primary key over (physician_id, department_id), so adding the same
   * department twice produces a duplicate-key error at flush time — a long way
   * from the line that actually caused it. Guarding here makes the method
   * idempotent, which in turn lets the importer call it blindly for every row
   * it sees without tracking what it has already attached.
   */
  public function addDepartment(Department $department): self {
    if (!$this->departments->contains($department)) {
      $this->departments->add($department);
      // Keep the inverse side consistent — see the note on $departments about
      // why Doctrine ignores it for persistence but callers still rely on it.
      $department->addPhysician($this);
    }
    return $this;
  }

  /**
   * @return Collection<int, Facility>
   */
  public function getFacilities(): Collection {
    return $this->facilities;
  }

  /**
   * Links this physician to a facility, both in memory and on flush.
   *
   * Same contract as addDepartment(): the contains() guard makes it idempotent,
   * which is what lets the importer call it blindly per row without tracking
   * what it has already attached. Without it, a physician listed twice at the
   * same site would violate the join table's composite primary key at flush
   * time — far from the line that caused it.
   */
  public function addFacility(Facility $facility): self {
    if (!$this->facilities->contains($facility)) {
      $this->facilities->add($facility);
      $facility->addPhysician($this);
    }
    return $this;
  }

  public function removeFacility(Facility $facility): self {
    if ($this->facilities->removeElement($facility)) {
      $facility->removePhysician($this);
    }
    return $this;
  }

  public function removeDepartment(Department $department): self {
    if ($this->departments->removeElement($department)) {
      $department->removePhysician($this);
    }
    return $this;
  }

  public function getCredentials(): string {
    return $this->credentials;
  }

  public function getLegalName(): string {
    return $this->legalName;
  }

  public function setBio(?string $bio): self {
    $this->bio = $bio;
    return $this;
  }

  public function setCredentials(string $credentials): self {
    $this->credentials = $credentials;
    return $this;
  }

  public function setLegalName(string $legalName): self {
    $this->legalName = $legalName;
    return $this;
  }

}
