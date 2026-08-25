<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum\EditableField;
use Pixiekat\HMFPSearchToolBundle\Enum\EditReviewStatus;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @extends ServiceEntityRepository<Entity\PhysicianEdit>
 */
class PhysicianEditRepository extends ServiceEntityRepository {

  public function __construct(
    ManagerRegistry $registry,
    private EntityManagerInterface $entityManager,
    private Security $security
  ) {
    parent::__construct($registry, Entity\PhysicianEdit::class);
  }

  /**
   * The live overrides for a set of physicians, in ONE query.
   *
   * ── Why this takes a list and not a single physician ──────────────────────
   * Because the caller is usually rendering a page of them. A per-physician
   * resolver looks tidier and turns a 20-result page into 20 extra queries —
   * the same N+1 that the taxonomy display already had to be rescued from.
   * Making the batch form the primary API means the cheap path is the obvious
   * one.
   *
   * ── Why the ordering matters ──────────────────────────────────────────────
   * Several Live edits may exist for the same field: superseding is bookkeeping
   * the manager does, and a failed run, a restored backup, or a direct database
   * edit can leave more than one behind. Ordering oldest-first and letting later
   * rows overwrite earlier ones in the map means the NEWEST always wins,
   * whatever the table looks like. The resolver therefore cannot be broken by
   * inconsistent status data — it degrades to "most recent wins", which is the
   * intended rule anyway.
   *
   * @param list<int> $physicianIds
   *
   * @return array<int, array<string, string|null>> physician id → field value → new value.
   */
  public function findLiveOverrides(array $physicianIds): array {
    if ($physicianIds === []) {
      return [];
    }

    $rows = $this->createQueryBuilder('e')
      ->select('IDENTITY(e.physician) AS physicianId', 'e.fieldName AS fieldName', 'e.newValue AS newValue')
      ->where('e.physician IN (:ids)')
      ->andWhere('e.reviewStatus = :live')
      ->setParameter('ids', $physicianIds)
      ->setParameter('live', EditReviewStatus::Live->value)
      ->orderBy('e.editedAt', 'ASC')
      ->addOrderBy('e.id', 'ASC')
      ->getQuery()
      ->getScalarResult();

    $overrides = [];
    foreach ($rows as $row) {
      // Later rows overwrite earlier ones — see the ordering note above.
      $overrides[(int) $row['physicianId']][(string) $row['fieldName']] = $row['newValue'];
    }

    return $overrides;
  }

  /**
   * Live edits for one physician and field, oldest first.
   *
   * Used when a new edit goes live and the previous ones must be marked
   * Superseded.
   *
   * @return Entity\PhysicianEdit[]
   */
  public function findLiveFor(Entity\Physician $physician, EditableField $field): array {
    return $this->createQueryBuilder('e')
      ->where('e.physician = :physician')
      ->andWhere('e.fieldName = :field')
      ->andWhere('e.reviewStatus = :live')
      ->setParameter('physician', $physician)
      ->setParameter('field', $field->value)
      ->setParameter('live', EditReviewStatus::Live->value)
      ->orderBy('e.editedAt', 'ASC')
      ->addOrderBy('e.id', 'ASC')
      ->getQuery()
      ->getResult();
  }

  /**
   * The review queue: everything still awaiting a decision, oldest first.
   *
   * Oldest-first because a review queue is a fairness problem — newest-first
   * means an edit proposed on a busy day can sit at the bottom forever.
   *
   * @return Entity\PhysicianEdit[]
   */
  public function findPending(int $limit = 50): array {
    return $this->createQueryBuilder('e')
      ->addSelect('p')
      ->join('e.physician', 'p')
      ->where('e.reviewStatus = :pending')
      ->setParameter('pending', EditReviewStatus::Pending->value)
      ->orderBy('e.editedAt', 'ASC')
      ->setMaxResults($limit)
      ->getQuery()
      ->getResult();
  }

  /**
   * Every edit ever made to a physician, newest first — the history view.
   *
   * @return Entity\PhysicianEdit[]
   */
  public function findHistoryFor(Entity\Physician $physician): array {
    return $this->createQueryBuilder('e')
      ->where('e.physician = :physician')
      ->setParameter('physician', $physician)
      ->orderBy('e.editedAt', 'DESC')
      ->addOrderBy('e.id', 'DESC')
      ->getQuery()
      ->getResult();
  }
}
