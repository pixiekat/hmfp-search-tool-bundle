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
   * finds all facilities, alphabetically.
   */
  public function findAllFacilities(): array {
    return $this->createQueryBuilder('f')
      ->orderBy('f.name', 'ASC')
      ->getQuery()
      ->getResult();
  }
}
