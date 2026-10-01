<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum\ClaimStatus;
use Pixiekat\HMFPSearchToolBundle\Enum\DelegationStatus;

/**
 * @extends ServiceEntityRepository<Entity\PhysicianDelegation>
 */
class PhysicianDelegationRepository extends ServiceEntityRepository {

  public function __construct(ManagerRegistry $registry) {
    parent::__construct($registry, Entity\PhysicianDelegation::class);
  }

  /**
   * Every physician this user may currently edit AS A DELEGATE.
   *
   * The delegate half of the voter's question, and the counterpart to
   * PhysicianClaimRepository::claimedPhysicianIdsFor() — same shape, same
   * reasons (a set of ids, fetched once per request).
   *
   * BOTH conditions are in the WHERE clause, and the second one is the
   * important one: the delegation is Accepted AND the claim it hangs off is
   * still live. That join is what makes revoking a claim cut off its delegates
   * instantly — there is no status on this table that has to be kept in step
   * for the permission to disappear. (DelegationManager::endAllForClaim() does
   * update the rows too, but only so the history reads truthfully; nothing
   * about access depends on it having run.)
   *
   * @return list<int>
   */
  public function delegatedPhysicianIdsFor(Entity\User $user): array {
    $rows = $this->createQueryBuilder('d')
      ->select('DISTINCT IDENTITY(c.physician) AS physicianId')
      ->join('d.claim', 'c')
      ->where('d.delegate = :user')
      ->andWhere('d.status = :accepted')
      ->andWhere('c.status IN (:granting)')
      ->setParameter('user', $user)
      ->setParameter('accepted', DelegationStatus::Accepted)
      ->setParameter('granting', ClaimStatus::grantingValues())
      ->getQuery()
      ->getScalarResult();

    return array_map(static fn (array $row): int => (int) $row['physicianId'], $rows);
  }

  /**
   * Every delegation ever made under a claim, newest first, with the delegate
   * joined — the claimant's "Delegates" page and the steward's claim screen.
   *
   * Finished ones included on purpose: a claimant who sees "Declined" next to an
   * address knows the invitation arrived, and one who sees nothing does not.
   *
   * @return list<Entity\PhysicianDelegation>
   */
  public function findForClaim(Entity\PhysicianClaim $claim): array {
    return $this->createQueryBuilder('d')
      ->addSelect('u')
      ->join('d.delegate', 'u')
      ->where('d.claim = :claim')
      ->setParameter('claim', $claim)
      ->orderBy('d.invitedAt', 'DESC')
      ->addOrderBy('d.id', 'DESC')
      ->getQuery()
      ->getResult();
  }

  /**
   * The open (Pending or Accepted) delegation for this person under this claim,
   * if there is one. UNIQ_PHYSDELEG_OPEN guarantees at most one.
   */
  public function openDelegationFor(Entity\PhysicianClaim $claim, Entity\User $delegate): ?Entity\PhysicianDelegation {
    return $this->createQueryBuilder('d')
      ->where('d.claim = :claim')
      ->andWhere('d.delegate = :delegate')
      ->andWhere('d.status IN (:open)')
      ->setParameter('claim', $claim)
      ->setParameter('delegate', $delegate)
      ->setParameter('open', [DelegationStatus::Pending->value, DelegationStatus::Accepted->value])
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
  }

  /**
   * The open delegations under a claim — what endAllForClaim() closes.
   *
   * @return list<Entity\PhysicianDelegation>
   */
  public function findOpenForClaim(Entity\PhysicianClaim $claim): array {
    return $this->createQueryBuilder('d')
      ->where('d.claim = :claim')
      ->andWhere('d.status IN (:open)')
      ->setParameter('claim', $claim)
      ->setParameter('open', [DelegationStatus::Pending->value, DelegationStatus::Accepted->value])
      ->getQuery()
      ->getResult();
  }

  /**
   * How many open delegations a claim has, for the MAX_OPEN_PER_CLAIM tripwire.
   */
  public function countOpenForClaim(Entity\PhysicianClaim $claim): int {
    return (int) $this->createQueryBuilder('d')
      ->select('COUNT(d.id)')
      ->where('d.claim = :claim')
      ->andWhere('d.status IN (:open)')
      ->setParameter('claim', $claim)
      ->setParameter('open', [DelegationStatus::Pending->value, DelegationStatus::Accepted->value])
      ->getQuery()
      ->getSingleScalarResult();
  }

  /**
   * Everybody who was EVER an active delegate under this claim — accepted at
   * some point, whether or not they still are.
   *
   * For revoke-and-revert: when a claim turns out to have been wrong, edits
   * made through it by its delegates are exactly as suspect as the claimant's
   * own. Keyed on accepted_at rather than status, because a delegate removed
   * last week still made the edits they made.
   *
   * @return list<Entity\User>
   */
  public function everAcceptedDelegatesOf(Entity\PhysicianClaim $claim): array {
    $delegations = $this->createQueryBuilder('d')
      ->addSelect('u')
      ->join('d.delegate', 'u')
      ->where('d.claim = :claim')
      ->andWhere('d.acceptedAt IS NOT NULL')
      ->setParameter('claim', $claim)
      ->getQuery()
      ->getResult();

    // De-duplicated by id: somebody removed and re-invited has two rows, and
    // their edits must be reverted once, not twice.
    $users = [];
    foreach ($delegations as $delegation) {
      $users[$delegation->getDelegate()->getId()] = $delegation->getDelegate();
    }

    return array_values($users);
  }

  /**
   * Whether this account was created by an invitation (any invitation, from any
   * physician) rather than by an administrator, registration or SSO.
   *
   * Asked when a SECOND invitation is sent to somebody who has not activated
   * yet — see DelegationManager::invite(). Together with "still inactive, still
   * no password", it is how the second invitation knows it may activate the
   * account too, without ever letting an invitation revive an account that an
   * administrator switched off.
   */
  public function wasCreatedByInvitation(Entity\User $user): bool {
    return (int) $this->createQueryBuilder('d')
      ->select('COUNT(d.id)')
      ->where('d.delegate = :user')
      ->andWhere('d.activatesAccount = true')
      ->setParameter('user', $user)
      ->getQuery()
      ->getSingleScalarResult() > 0;
  }

  /**
   * Finds a delegation by the PLAINTEXT token from an emailed link.
   *
   * Expiry is NOT part of the query, for PhysicianClaimRepository::findByToken()'s
   * reason: an expired link should be reported as expired, not as invalid.
   */
  public function findByToken(string $token): ?Entity\PhysicianDelegation {
    return $this->createQueryBuilder('d')
      ->where('d.tokenHash = :hash')
      ->setParameter('hash', hash('sha256', $token))
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
  }
}
