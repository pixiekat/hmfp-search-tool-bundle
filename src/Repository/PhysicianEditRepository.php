<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum\EditableField;
use Pixiekat\HMFPSearchToolBundle\Enum\EditReviewStatus;
use Pixiekat\SymfonyHelpers\Traits\Repository\PaginationTrait;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @extends ServiceEntityRepository<Entity\PhysicianEdit>
 */
class PhysicianEditRepository extends ServiceEntityRepository {
  use PaginationTrait;

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
