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

  /*
   * ── Where this site actually is ──────────────────────────────────────────
   * None of this comes from the provider demographics extract, which supplies
   * a bare `facility_name` and nothing else. It is entered by hand through the
   * admin — which sounds like a workaround until you count the rows: there are
   * TWENTY facilities. That is a short afternoon, not an integration, and it
   * avoids depending on a geocoding service to place a list that changes
   * perhaps twice a year.
   *
   * Every field is nullable. A facility with no address is still a facility,
   * and the importer will keep creating them before anyone fills the rest in;
   * the search simply cannot place them until someone does.
   */

  #[ORM\Column(name: 'address_line', type: 'string', length: 255, nullable: true)]
  private ?string $addressLine = null;

  #[ORM\Column(type: 'string', length: 128, nullable: true)]
  private ?string $city = null;

  /**
   * Two-letter state code. Length 8 rather than 2 leaves room for the non-US
   * and territory codes a Boston hospital network will eventually meet.
   */
  #[ORM\Column(type: 'string', length: 8, nullable: true)]
  private ?string $state = null;

  /**
   * Postal code as TEXT, never a number.
   *
   * A ZIP is an identifier written with digits, not a quantity. Storing it
   * numerically drops the leading zero from every code in New England — 02215
   * becomes 2215 — which is precisely the region this directory serves.
   */
  #[ORM\Column(name: 'postal_code', type: 'string', length: 16, nullable: true)]
  private ?string $postalCode = null;

  /*
   * ── Coordinates ──────────────────────────────────────────────────────────
   * DECIMAL, not FLOAT. A float cannot represent most decimal fractions
   * exactly, so coordinates drift on round-trips and two "identical" points
   * stop comparing equal. DECIMAL(10,7) resolves to about 11mm, far finer than
   * a building needs.
   *
   * Deliberately NOT a spatial POINT column with a spatial index. That
   * machinery earns its keep over thousands of rows; there are twenty here, so
   * computing the exact distance to every one costs microseconds and keeps the
   * code portable and readable. See FacilityRepository::near().
   */
  #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
  private ?string $latitude = null;

  #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
  private ?string $longitude = null;

  /**
   * The site's identifier in Epic, for the integration in a later phase.
   *
   * Unique, because two facilities sharing one Epic id is a contradiction —
   * and NULLs are exempt from a unique index in MySQL, so the nineteen without
   * one coexist happily.
   */
  #[ORM\Column(name: 'epic_id', type: 'string', length: 64, nullable: true, unique: true)]
  private ?string $epicId = null;

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

  /**
   * Latitude as a float, or null if this site has not been placed.
   *
   * Doctrine hands DECIMAL back as a STRING, preserving the exactness that
   * choosing DECIMAL was about. Callers doing arithmetic want a float, so the
   * conversion happens once here rather than being remembered at every call —
   * forgetting it yields string arithmetic that silently works for "42.3" and
   * breaks on the first null.
   */
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
