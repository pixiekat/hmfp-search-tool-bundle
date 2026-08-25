<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\SymfonyHelpers\Traits\Repository\PaginationTrait;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @extends ServiceEntityRepository<Entity\Facility>
 *
 * @method Entity\Facility|null find($id, $lockMode = null, $lockVersion = null)
 * @method Entity\Facility|null findOneBy(array $criteria, array $orderBy = null)
 * @method Entity\Facility[]    findAll()
 * @method Entity\Facility[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FacilityRepository extends ServiceEntityRepository {
  use PaginationTrait;

  public function __construct(
    ManagerRegistry $registry,
    private EntityManagerInterface $entityManager,
    private Security $security
  ) {
    parent::__construct($registry, Entity\Facility::class);
  }

  /**
   * Mean radius of the Earth in miles.
   *
   * Miles because the audience is a Boston hospital network and patients think
   * in miles. Change this constant to 6371.0 for kilometres — it is the only
   * unit-dependent value in the class.
   */
  private const EARTH_RADIUS_MILES = 3958.8;

  /**
   * finds all facilities, alphabetically.
   */
  public function findAllFacilities(): array {
    return $this->createQueryBuilder('f')
      ->orderBy('f.name', 'ASC')
      ->getQuery()
      ->getResult();
  }

  /**
   * Facilities that have been given coordinates.
   *
   * @return Entity\Facility[]
   */
  public function findPlaced(): array {
    return $this->createQueryBuilder('f')
      ->where('f.latitude IS NOT NULL')
      ->andWhere('f.longitude IS NOT NULL')
      ->orderBy('f.name', 'ASC')
      ->getQuery()
      ->getResult();
  }

  /**
   * Facilities within a radius of a point, nearest first.
   *
   * ── Why this is done in PHP and not in SQL ────────────────────────────────
   * The textbook answer is a POINT column, a SPATIAL index and
   * ST_Distance_Sphere — and it is the wrong answer here. All of that machinery
   * exists to avoid scanning a large table; this one holds TWENTY rows.
   * Loading all twenty and computing exact distances costs microseconds, needs
   * no spatial types, and can be read and tested by anyone.
   *
   * It also keeps the maths honest: an index-assisted spatial query usually
   * needs a bounding-box pre-filter, and a naive bounding box in degrees is
   * wrong at anything other than the equator, because a degree of longitude
   * shrinks as you move away from it. Skipping the optimisation skips the bug.
   *
   * The trigger for revisiting is size, not principle: at a few thousand sites
   * this becomes worth indexing.
   *
   * ── Haversine, not Pythagoras ─────────────────────────────────────────────
   * Treating latitude and longitude as a flat grid is out by several percent
   * over the distances a patient cares about, and gets worse with latitude. At
   * Boston's 42°N a degree of longitude is about 74% the length of a degree of
   * latitude, so flat-earth distance overstates east-west separation badly.
   *
   * @return list<array{facility: Entity\Facility, miles: float}> nearest first.
   */
  public function near(float $latitude, float $longitude, float $radiusMiles): array {
    $within = [];

    foreach ($this->findPlaced() as $facility) {
      $miles = $this->distanceMiles(
        $latitude,
        $longitude,
        (float) $facility->getLatitude(),
        (float) $facility->getLongitude(),
      );

      if ($miles <= $radiusMiles) {
        $within[] = ['facility' => $facility, 'miles' => $miles];
      }
    }

    usort($within, static fn (array $a, array $b): int => $a['miles'] <=> $b['miles']);

    return $within;
  }

  /**
   * Great-circle distance between two points, in miles.
   *
   * The standard haversine formula. asin(sqrt(...)) rather than the algebraically
   * equivalent atan2 form because it is the version most readers will recognise,
   * and the numerical instability haversine is famous for only appears at
   * near-antipodal distances — which no two facilities in Massachusetts are.
   */
  public function distanceMiles(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);

    $a = sin($latDelta / 2) ** 2
      + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

    // min(1.0, ...) guards against floating-point error pushing the value a
    // hair above 1, which would make asin() return NAN for two points that are
    // merely very far apart.
    return 2 * self::EARTH_RADIUS_MILES * asin(min(1.0, sqrt($a)));
  }
}
