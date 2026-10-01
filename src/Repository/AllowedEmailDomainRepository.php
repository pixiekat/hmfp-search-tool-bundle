<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\HMFPSearchToolBundle\Entity;

/**
 * @extends ServiceEntityRepository<Entity\AllowedEmailDomain>
 */
class AllowedEmailDomainRepository extends ServiceEntityRepository {

  public function __construct(ManagerRegistry $registry) {
    parent::__construct($registry, Entity\AllowedEmailDomain::class);
  }

  /**
   * Whether this (already normalised) domain is on the list.
   *
   * A COUNT on the unique index — one row or none — rather than loading the
   * whole list into PHP and searching it.
   */
  public function isListed(string $normalisedDomain): bool {
    return (int) $this->createQueryBuilder('d')
      ->select('COUNT(d.id)')
      ->where('d.domain = :domain')
      ->setParameter('domain', $normalisedDomain)
      ->getQuery()
      ->getSingleScalarResult() > 0;
  }

  /**
   * The whole list, alphabetical, for the admin screen and for the hint on the
   * invite form ("addresses at …").
   *
   * @return list<Entity\AllowedEmailDomain>
   */
  public function findAllOrdered(): array {
    return $this->findBy([], ['domain' => 'ASC']);
  }
}
