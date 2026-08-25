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
   * Every non-rejected edit for a field is returned, not just the newest, and
   * ordering oldest-first lets later rows overwrite earlier ones in the map so
   * the NEWEST wins. That is the whole resolution rule, and doing it this way
   * is what makes rejection a revert for free: reject the newest and the one
   * before it is simply the latest non-rejected edit next time round, with no
   * status anywhere needing to be un-set.
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
      ->andWhere('e.reviewStatus IN (:published)')
      ->setParameter('ids', $physicianIds)
      ->setParameter('published', EditReviewStatus::publishedValues())
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
   * Published edits for one physician and field, oldest first.
   *
   * @return Entity\PhysicianEdit[]
   */
  public function findPublishedFor(Entity\Physician $physician, EditableField $field): array {
    return $this->createQueryBuilder('e')
      ->where('e.physician = :physician')
      ->andWhere('e.fieldName = :field')
      ->andWhere('e.reviewStatus IN (:published)')
      ->setParameter('physician', $physician)
      ->setParameter('field', $field->value)
      ->setParameter('published', EditReviewStatus::publishedValues())
      ->orderBy('e.editedAt', 'ASC')
      ->addOrderBy('e.id', 'ASC')
      ->getQuery()
      ->getResult();
  }

  /**
   * The review queue: live edits nobody has checked yet, oldest first.
   *
   * Oldest-first because a review queue is a fairness problem — newest-first
   * means an edit proposed on a busy day can sit at the bottom forever.
   *
   * @return Entity\PhysicianEdit[]
   */
  public function findUnreviewed(int $limit = 50): array {
    return $this->createQueryBuilder('e')
      ->addSelect('p')
      ->join('e.physician', 'p')
      ->where('e.reviewStatus = :unreviewed')
      ->setParameter('unreviewed', EditReviewStatus::Unreviewed->value)
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
