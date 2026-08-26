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

  #[ORM\Column(name: 'address_line', type: 'string', length: 255, nullable: true)]
  private ?string $addressLine = null;

  #[ORM\Column(type: 'string', length: 128, nullable: true)]
  private ?string $city = null;

  // Two-letter state code. Length 8 rather than 2 leaves room for the non-US
  // and territory codes a Boston hospital network will eventually meet.
  #[ORM\Column(type: 'string', length: 8, nullable: true)]
  private ?string $state = null;

  #[ORM\Column(name: 'postal_code', type: 'string', length: 16, nullable: true)]
  private ?string $postalCode = null;

  #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
  private ?string $latitude = null;

  #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
  private ?string $longitude = null;

  #[ORM\Column(name: 'epic_id', type: 'string', length: 64, nullable: true, unique: true)]
  private ?string $epicId = null;

  /**
   * The physicians practicing at this facility.
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

  public function getAddressLine(): ?string { return $this->addressLine; }
  public function getCity(): ?string { return $this->city; }
  public function getState(): ?string { return $this->state; }
  public function getPostalCode(): ?string { return $this->postalCode; }
  public function getEpicId(): ?string { return $this->epicId; }

  public function setAddressLine(?string $addressLine): self { $this->addressLine = $addressLine; return $this; }
  public function setCity(?string $city): self { $this->city = $city; return $this; }
  public function setState(?string $state): self { $this->state = $state; return $this; }
  public function setPostalCode(?string $postalCode): self { $this->postalCode = $postalCode; return $this; }
  public function setEpicId(?string $epicId): self { $this->epicId = $epicId; return $this; }

  public function getLatitude(): ?float {
    return $this->latitude === null ? null : (float) $this->latitude;
  }

  public function getLongitude(): ?float {
    return $this->longitude === null ? null : (float) $this->longitude;
  }

  public function setLatitude(float|string|null $latitude): self {
    $this->latitude = ($latitude === null || $latitude === '') ? null : (string) $latitude;
    return $this;
  }

  public function setLongitude(float|string|null $longitude): self {
    $this->longitude = ($longitude === null || $longitude === '') ? null : (string) $longitude;
    return $this;
  }

  /**
   * Whether this facility can take part in a distance search.
   *
   * Both coordinates or neither — a half-placed site would be silently
   * mis-located rather than simply excluded, which is the worse failure.
   */
  public function hasCoordinates(): bool {
    return $this->latitude !== null && $this->longitude !== null;
  }

  /**
   * A one-line address for display, skipping whatever is missing.
   */
  public function getFormattedAddress(): ?string {
    $parts = array_filter([
      $this->addressLine,
      $this->city,
      trim(($this->state ?? '') . ' ' . ($this->postalCode ?? '')),
    ], static fn (?string $p): bool => $p !== null && trim($p) !== '');

    return $parts === [] ? null : implode(', ', $parts);
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
