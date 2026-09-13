<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum\ClaimStatus;
use Pixiekat\SymfonyHelpers\Traits\Repository\PaginationTrait;

/**
 * @extends ServiceEntityRepository<Entity\PhysicianClaim>
 */
class PhysicianClaimRepository extends ServiceEntityRepository {
  use PaginationTrait;

  public function __construct(
    ManagerRegistry $registry,
    private EntityManagerInterface $entityManager,
  ) {
    parent::__construct($registry, Entity\PhysicianClaim::class);
  }

  /**
   * Return all claims in the system joining physician and users
   * for the admin review.
   */
  public function findAllClaimsWithPhysicianAndUser(): array {
    return $this->createQueryBuilder('c')
      ->addSelect('p', 'u')
      ->join('c.physician', 'p')
      ->join('c.user', 'u')
      ->orderBy('c.claimedAt', 'DESC')
      ->getQuery()
      ->getResult();
  }

  /**
   * Every physician this user is currently allowed to speak for.
   *
   * THE query behind the voter, so the shape matters. Returns the whole SET in
   * one query rather than answering one physician at a time, because the search
   * results template asks the permission question once per row — twenty results
   * meant twenty COUNT queries before this existed. The voter fetches this once
   * per request and answers from memory thereafter.
   *
   * A list rather than a single id: UNIQ_PHYSCLAIM_ACTIVE enforces one live claim
   * per PHYSICIAN, not per USER, so an account may legitimately hold several — a
   * duplicated provider record, a locum, or somebody who tested the flow twice.
   * Asking for "the" one silently honoured whichever sorted first and ignored the
   * rest, which is a permission quietly not granted.
   *
   * IDs rather than entities: the caller compares integers, and hydrating a
   * Physician — with its lazy collections of terms, departments and facilities —
   * to answer a question about an integer is work nobody asked for.
   *
   * @return list<int>
   */
  public function claimedPhysicianIdsFor(Entity\User $user): array {
    $rows = $this->createQueryBuilder('c')
      ->select('IDENTITY(c.physician) AS physicianId')
      ->where('c.user = :user')
      ->andWhere('c.status IN (:granting)')
      ->setParameter('user', $user)
      ->setParameter('granting', ClaimStatus::grantingValues())
      ->getQuery()
      ->getScalarResult();

    return array_map(static fn (array $row): int => (int) $row['physicianId'], $rows);
  }

  /**
   * Whether this user holds a live claim on THIS physician.
   *
   * A boolean rather than "the user's physician id", because nothing constrains a
   * user to one claim: UNIQ_PHYSCLAIM_ACTIVE enforces one live claim per
   * PHYSICIAN, not per user, so an account may legitimately hold several — a
   * duplicated record, a locum, or somebody who tested the flow twice. Asking for
   * "the" one silently honours whichever sorted first and ignores the rest.
   *
   * COUNT, so nothing is hydrated. Covered by IDX_PHYSCLAIM_USER_STATUS.
   */
  public function hasActiveClaimOn(Entity\User $user, Entity\Physician $physician): bool {
    return (int) $this->createQueryBuilder('c')
      ->select('COUNT(c.id)')
      ->where('c.user = :user')
      ->andWhere('c.physician = :physician')
      ->andWhere('c.status IN (:granting)')
      ->setParameter('user', $user)
      ->setParameter('physician', $physician)
      ->setParameter('granting', ClaimStatus::grantingValues())
      ->getQuery()
      ->getSingleScalarResult() > 0;
  }

  /**
   * The live claim on a physician, if somebody holds it.
   *
   * Used by the profile page to decide between "Are you this physician?" and
   * "This profile has been claimed", and by the steward screens to show who by.
   */
  public function activeClaimOn(Entity\Physician $physician): ?Entity\PhysicianClaim {
    return $this->createQueryBuilder('c')
      ->where('c.physician = :physician')
      ->andWhere('c.status IN (:granting)')
      ->setParameter('physician', $physician)
      ->setParameter('granting', ClaimStatus::grantingValues())
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
  }

  /**
   * This user's outstanding claim on this physician, if they have started one.
   *
   * Stops the profile page offering "Are you this physician?" to somebody who
   * already has a confirmation email sitting in their inbox, and gives the
   * controller something to re-send a token against rather than piling up a new
   * Pending row per click.
   */
  public function pendingClaimFor(Entity\Physician $physician, Entity\User $user): ?Entity\PhysicianClaim {
    return $this->createQueryBuilder('c')
      ->where('c.physician = :physician')
      ->andWhere('c.user = :user')
      ->andWhere('c.status = :pending')
      ->setParameter('physician', $physician)
      ->setParameter('user', $user)
      ->setParameter('pending', ClaimStatus::Pending)
      ->orderBy('c.id', 'DESC')
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
  }

  /**
   * Finds a claim by the PLAINTEXT token from an emailed link.
   *
   * Hashes first and looks the hash up, because the plaintext is not stored —
   * see PhysicianClaim::$tokenHash. The expiry is deliberately NOT part of this
   * query: an expired token should find its claim and then be reported as
   * expired, which is a different message to the user than "this link is not
   * valid" and saves them wondering whether they mistyped it.
   */
  public function findByToken(string $token): ?Entity\PhysicianClaim {
    return $this->createQueryBuilder('c')
      ->where('c.tokenHash = :hash')
      ->setParameter('hash', hash('sha256', $token))
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
  }

  /**
   * Claims a steward still has to look at, oldest first.
   *
   * Oldest first because this is a work queue and the oldest item is the one
   * somebody has been waiting longest on. Covered by IDX_PHYSCLAIM_QUEUE.
   *
   * @return list<Entity\PhysicianClaim>
   */
  public function findOpenQueue(int $limit = 50): array {
    return $this->createQueryBuilder('c')
      ->where('c.status IN (:open)')
      ->setParameter('open', [ClaimStatus::Pending->value, ClaimStatus::Verified->value])
      ->orderBy('c.claimedAt', 'ASC')
      ->setMaxResults($limit)
      ->getQuery()
      ->getResult();
  }

  /**
   * The stewards' work queue: claims still needing a human, oldest first.
   *
   * Oldest first because it is a queue — the top item is the person who has waited
   * longest, not the most recent arrival. The full-history list orders the other
   * way for the opposite reason.
   *
   * Open means Pending or Verified. Pending obviously needs somebody; Verified is
   * live already but nobody has looked, and approving it is what clears it from
   * here. Without that second case the queue would only ever contain claims that
   * self-service could not finish, and every self-verified claim would pass
   * unexamined.
   *
   * Physician and claimant are fetch-joined: the template prints both on every row,
   * and lazy-loading them costs two queries per claim. Inner joins are safe because
   * both columns are NOT NULL with CASCADE deletes, so neither can drop a row.
   *
   * @return list<Entity\PhysicianClaim>
   */
  public function findOpenQueueWithRelations(int $limit = 100): array {
    return $this->createQueryBuilder('c')
      ->addSelect('p', 'u')
      ->join('c.physician', 'p')
      ->join('c.user', 'u')
      ->where('c.status IN (:open)')
      ->setParameter('open', [ClaimStatus::Pending->value, ClaimStatus::Verified->value])
      ->orderBy('c.claimedAt', 'ASC')
      // Tie-breaker, so two claims made in the same second have a defined order
      // rather than whichever the engine happens to return first.
      ->addOrderBy('c.id', 'ASC')
      ->setMaxResults($limit)
      ->getQuery()
      ->getResult();
  }

  /**
   * How many claims are waiting, for a badge or a heading.
   */
  public function countOpen(): int {
    return (int) $this->createQueryBuilder('c')
      ->select('COUNT(c.id)')
      ->where('c.status IN (:open)')
      ->setParameter('open', [ClaimStatus::Pending->value, ClaimStatus::Verified->value])
      ->getQuery()
      ->getSingleScalarResult();
  }

  /**
   * Every claim ever made on a physician, newest first.
   *
   * The contested cases are the point: three people claiming one David Lee is
   * invisible in findOpenQueue() once two of them have been refused, and this is
   * where a steward sees the shape of it.
   *
   * @return list<Entity\PhysicianClaim>
   */
  public function findHistoryFor(Entity\Physician $physician): array {
    return $this->createQueryBuilder('c')
      ->where('c.physician = :physician')
      ->setParameter('physician', $physician)
      ->orderBy('c.claimedAt', 'DESC')
      ->addOrderBy('c.id', 'DESC')
      ->getQuery()
      ->getResult();
  }

  /**
   * Checks if a physician has never had a claim, this indicates that the physician is
   * unclaimed and can be claimed by a user. This is used to determine if the "Claim this profile" button should be displayed on the physician profile page.
   *
   * @param Entity\Physician $physician
   * @return bool
   */
  public function isPhysicianUnclaimed(Entity\Physician $physician): bool {
    $count = $this->createQueryBuilder('c')
      ->select('COUNT(c.id)')
      ->where('c.physician = :physician')
      ->andWhere('c.status IN (:granting)')
      ->setParameter('physician', $physician)
      ->setParameter('granting', ClaimStatus::grantingValues())
      ->getQuery()
      ->getSingleScalarResult();

    return $count == 0;
  }

  /**
   * Check if a user has any active claims on any physician. Only active or pending, rejected claims are not considered active so they can claim again.
   */
  public function hasActiveClaims(Entity\User $user): bool {
    $count = $this->createQueryBuilder('c')
      ->select('COUNT(c.id)')
      ->where('c.user = :user')
      ->andWhere('c.status IN (:granting)')
      ->setParameter('user', $user)
      ->setParameter('granting', ClaimStatus::grantingValues())
      ->getQuery()
      ->getSingleScalarResult();

    return $count > 0;
  }
}
