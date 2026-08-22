<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\SymfonyHelpers\Traits\Repository\PaginationTrait;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @extends ServiceEntityRepository<Entity\Physician>
 *
 * @method Entity\Physician|null find($id, $lockMode = null, $lockVersion = null)
 * @method Entity\Physician|null findOneBy(array $criteria, array $orderBy = null)
 * @method Entity\Physician[]    findAll()
 * @method Entity\Physician[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PhysicianRepository extends ServiceEntityRepository {
  use PaginationTrait;

  public function __construct(
    ManagerRegistry $registry,
    private EntityManagerInterface $entityManager,
    private Security $security
  ) {
    parent::__construct($registry, Entity\Physician::class);
  }

  /**
   * Finds physicians who were in a previous import but not the most recent one.
   *
   * ── The NULL trap ─────────────────────────────────────────────────────────
   * The `IS NOT NULL` half of this condition is not redundant, and dropping it
   * is the mistake this method exists to prevent. Three populations share the
   * lastSeenInImportAt column:
   *
   *   stamped with the latest run  — still employed, seen in the newest extract
   *   stamped with an older run    — DEPARTED, which is what we want here
   *   NULL                         — never came from an import at all, i.e.
   *                                  created by hand in the admin UI
   *
   * A bare `< :lastRun` would try to sweep up that third group. Because a NULL
   * comparison in SQL is neither true nor false, the bug shows up as physicians
   * mysteriously absent from a list rather than as an error — much harder to
   * spot. Hand-created physicians were never in an extract, so they cannot have
   * departed from one.
   *
   * @param \DateTimeImmutable $lastRun Timestamp of the most recent import run.
   *
   * @return Entity\Physician[]
   */
  public function findDepartedSince(\DateTimeImmutable $lastRun): array {
    return $this->departedSinceQueryBuilder($lastRun)
      ->orderBy('p.legalName', 'ASC')
      ->getQuery()
      ->getResult();
  }

  /**
   * Counts physicians who were in a previous import but not the most recent one.
   *
   * Separate from findDepartedSince() so a summary line need not hydrate
   * thousands of entities just to call count() on them.
   */
  public function countDepartedSince(\DateTimeImmutable $lastRun): int {
    return (int) $this->departedSinceQueryBuilder($lastRun)
      ->select('COUNT(p.id)')
      ->getQuery()
      ->getSingleScalarResult();
  }

  /**
   * The shared condition behind findDepartedSince() and countDepartedSince().
   *
   * Factored out so the two cannot drift apart — a count that disagrees with the
   * list it summarises is a uniquely annoying bug to chase.
   */
  private function departedSinceQueryBuilder(\DateTimeImmutable $lastRun): \Doctrine\ORM\QueryBuilder {
    return $this->createQueryBuilder('p')
      ->andWhere('p.lastSeenInImportAt IS NOT NULL')
      ->andWhere('p.lastSeenInImportAt < :lastRun')
      ->setParameter('lastRun', $lastRun);
  }
}
