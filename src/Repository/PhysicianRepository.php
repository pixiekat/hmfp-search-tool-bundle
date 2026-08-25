<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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
   * Relevance tiers for search(), highest first.
   *
   * Named rather than inlined as magic numbers so the ORDER OF PREFERENCE is
   * legible in one place, and so a template can explain to a user why a result
   * ranked where it did. The gaps between values are deliberate — there is room
   * to slot a new tier in without renumbering the rest.
   */
  public const SCORE_EXACT_FULL    = 100; // "anne m. valente" typed in full
  public const SCORE_EXACT_SURNAME = 90;  // "valente"
  public const SCORE_PREFIX_FULL   = 80;  // "anne m. val…"
  public const SCORE_PREFIX_SURNAME = 70; // "val…" matching Valente
  public const SCORE_CONTAINS      = 60;  // "lent" appearing anywhere
  public const SCORE_PHONETIC      = 30;  // "smyth" reaching Smith — see below
  public const SCORE_BROWSE        = 0;   // no search term; filters only

  /**
   * The many-to-many taxonomies that can be filtered on.
   *
   * Every taxonomy hanging off Physician has the same shape — a join table with
   * physician_id on one side and the taxonomy's id on the other — so filtering
   * is described here as data rather than written out per taxonomy in search().
   * Six more are planned (Specialty, ClinicalInterest, Condition, Procedure,
   * BoardCertification, Language) and each should cost one line here plus a
   * control in the template, not another branch in the query builder.
   */
  private const TAXONOMY_FILTERS = [
    'department' => ['table' => 'physician_departments', 'column' => 'department_id'],
    'facility'   => ['table' => 'physician_facilities',  'column' => 'facility_id'],
    // Planned, once the entities land:
    // 'specialty'  => ['table' => 'physician_specialty',  'column' => 'specialty_id'],
    // 'language'   => ['table' => 'physician_language',   'column' => 'language_id'],
    // 'condition'  => ['table' => 'physician_condition',  'column' => 'condition_id'],
  ];

  /**
   * The taxonomy keys this repository knows how to filter on.
   *
   * Exposed so a controller can read the request without hard-coding the same
   * list a second time — the two would drift the moment a taxonomy is added.
   *
   * @return list<string>
   */
  public static function filterableTaxonomies(): array {
    return array_keys(self::TAXONOMY_FILTERS);
  }

  /**
   * Searches physicians by name, with optional filters.
   *
   * NOTE: Raw SQL is used here rather than DQL because of the use of
   * SOUNDEX() and SUBSTRING_INDEX(), which have no DQL equivalent. This allows
   * for phonetic matching and surname extraction directly in the query.
   *
   * NOTE: FULLTEXT search is not used here because MariaDB's innodb_ft_min_token_size
   * defaults to 3, which would make physicians with surnames shorter than that
   * (e.g., Lu, Li, Ma, Ho, Wu) unfindable. A plain LIKE scan is used instead,
   * which does not have this limitation.
   *
   * @param string $term Free-text name query; '' browses by filter alone.
   * @param array $taxonomyFilters Keyed by taxonomy — see self::TAXONOMY_FILTERS.
   * @param ?string $credential Restrict to this PRIMARY credential, e.g. 'MD'.
   * @param int $page The page number for pagination (1-based).
   * @param int $perPage The number of results per page for pagination.
   * @return array{results: Entity\Physician[], total: int, scores: array<int, int>}
   */
  public function search(
    string $term,
    array $taxonomyFilters = [],
    ?string $credential = null,
    int $page = 1,
    int $perPage = 20,
  ): array {
    $term = trim($term);
    $page = max(1, $page);

    $where  = [];
    $params = [];

    // $term is the search query that we're searching for. Obviously we don't
    // run an empty search.
    if ($term !== '') {
      // LOWER() on both sides rather than relying on the column's collation.
      // utf8mb4_general_ci already compares case-insensitively, but stating it
      // explicitly means the behaviour does not silently change if someone
      // later alters the collation to a _bin or _cs variant.
      $where[] = '(LOWER(p.legal_name) LIKE :contains '
        . "OR SOUNDEX(SUBSTRING_INDEX(p.legal_name, ' ', -1)) = SOUNDEX(:raw))";

      $params['raw']      = mb_strtolower($term);
      $params['prefix']   = $this->escapeLike(mb_strtolower($term)) . '%';
      $params['contains'] = '%' . $this->escapeLike(mb_strtolower($term)) . '%';
    }

    // Taxonomy filters
    // Driven by self::TAXONOMY_FILTERS rather than written out per taxonomy,
    // because six more are coming — Specialty, ClinicalInterest, Condition,
    // Procedure, BoardCertification, Language — and every one of them is the
    // same many-to-many shape. Adding one should be a line of configuration,
    // not another copy of this block.
    $aliasIndex = 0;
    foreach ($taxonomyFilters as $taxonomy => $id) {
      if ($id === null || !isset(self::TAXONOMY_FILTERS[$taxonomy])) {
        continue;
      }

      $join        = self::TAXONOMY_FILTERS[$taxonomy];
      $alias       = 'tx' . $aliasIndex++;
      $placeholder = 'tx_' . $taxonomy;

      $where[] = sprintf(
        'EXISTS (SELECT 1 FROM %s %s WHERE %s.physician_id = p.id AND %s.%s = :%s)',
        $join['table'],
        $alias,
        $alias,
        $alias,
        $join['column'],
        $placeholder,
      );

      $params[$placeholder] = (int) $id;
    }

    if ($credential !== null && $credential !== '') {
      // Matches the PRIMARY credential only: "MD, PhD" and "MD, MPH" both
      // filter under "MD". There are 193 distinct full credential strings but
      // only 28 distinct primary ones, which is the difference between an
      // unusable dropdown and a usable one.
      $where[] = "TRIM(SUBSTRING_INDEX(p.credentials, ',', 1)) = :credential";
      $params['credential'] = $credential;
    }

    $whereSql = $where === [] ? '1 = 1' : implode(' AND ', $where);

    // Count first
    // A separate COUNT rather than SQL_CALC_FOUND_ROWS, which is deprecated in
    // MySQL 8 and was never faster in MariaDB. Two simple queries beat one
    // clever one.
    $connection = $this->entityManager->getConnection();

    $total = (int) $connection->executeQuery(
      sprintf('SELECT COUNT(*) FROM physicians p WHERE %s', $whereSql),
      $params,
    )->fetchOne();

    if ($total === 0) {
      return ['results' => [], 'total' => 0, 'scores' => []];
    }

    // The scored page
    $scoreSql = $term === ''
      ? (string) self::SCORE_BROWSE
      : sprintf(
        'CASE
            WHEN LOWER(p.legal_name) = :raw THEN %d
            WHEN LOWER(SUBSTRING_INDEX(p.legal_name, \' \', -1)) = :raw THEN %d
            WHEN LOWER(p.legal_name) LIKE :prefix THEN %d
            WHEN LOWER(SUBSTRING_INDEX(p.legal_name, \' \', -1)) LIKE :prefix THEN %d
            WHEN LOWER(p.legal_name) LIKE :contains THEN %d
            ELSE %d
        END',
        self::SCORE_EXACT_FULL,
        self::SCORE_EXACT_SURNAME,
        self::SCORE_PREFIX_FULL,
        self::SCORE_PREFIX_SURNAME,
        self::SCORE_CONTAINS,
        self::SCORE_PHONETIC,
      );

    // LIMIT/OFFSET are cast to int rather than bound. Placeholders in LIMIT
    // need explicit integer binding to avoid being quoted as strings, and an
    // (int) cast is both simpler and unambiguously injection-proof — a PHP int
    // cannot carry SQL.
    $rows = $connection->executeQuery(
      sprintf(
        'SELECT p.id, %s AS score
            FROM physicians p
          WHERE %s
          ORDER BY score DESC, p.legal_name ASC
          LIMIT %d OFFSET %d',
        $scoreSql,
        $whereSql,
        max(1, $perPage),
        ($page - 1) * max(1, $perPage),
      ),
      $params,
    )->fetchAllAssociative();

    if ($rows === []) {
      // A page past the end of the result set.
      return ['results' => [], 'total' => $total, 'scores' => []];
    }

    $ids    = array_map(static fn (array $r): int => (int) $r['id'], $rows);
    $scores = [];
    foreach ($rows as $row) {
      $scores[(int) $row['id']] = (int) $row['score'];
    }

    // Hydrate the page with one ORM query with both collections eagerly joined,
    // so rendering a result list does not fire two lazy SELECTs per physician
    // (an N+1 that would turn 20 results into 41 queries). Joining two to-many
    // collections at once produces a cartesian product — a physician in 3 departments
    // at 4 facilities yields 12 result rows. Doctrine collapses them back into one
    // entity, and at 20 physicians per page the row count stays trivial. It would
    // NOT be safe with a Paginator, where the multiplied rows would corrupt LIMIT;
    // that is precisely why the page was selected by id above, before this query runs.
    $physicians = $this->createQueryBuilder('p')
      ->addSelect('d', 'f')
      ->leftJoin('p.departments', 'd')
      ->leftJoin('p.facilities', 'f')
      ->where('p.id IN (:ids)')
      ->setParameter('ids', $ids)
      ->getQuery()
      ->getResult();

    // SQL makes no promise about the order of an IN() result, so restore the
    // ranking the scoring query established.
    $byId = [];
    foreach ($physicians as $physician) {
      $byId[$physician->getId()] = $physician;
    }

    $ordered = [];
    foreach ($ids as $id) {
      if (isset($byId[$id])) {
        $ordered[] = $byId[$id];
      }
    }

    return ['results' => $ordered, 'total' => $total, 'scores' => $scores];
  }

  /**
   * The distinct PRIMARY credentials, for the filter dropdown.
   *
   * "MD, PhD" and "MD, MPH" both reduce to "MD", turning 193 unusable options
   * into 28 usable ones. Ordered by frequency so the common ones are reachable
   * without scrolling, which matters more than alphabetical order on a control
   * most people will use with the keyboard.
   *
   * @return array<int, array{credential: string, total: int}>
   */
  public function findPrimaryCredentials(): array {
    $rows = $this->entityManager->getConnection()->executeQuery(
      "SELECT TRIM(SUBSTRING_INDEX(credentials, ',', 1)) AS credential, COUNT(*) AS total
          FROM physicians
        GROUP BY credential
        HAVING credential <> ''
        ORDER BY total DESC, credential ASC"
    )->fetchAllAssociative();

    return array_map(
      static fn (array $r): array => [
        'credential' => (string) $r['credential'],
        'total'      => (int) $r['total'],
      ],
      $rows,
    );
  }

  /**
   * Escapes the wildcards LIKE treats as special.
   *
   * Without this, a user searching for "100%" would have their % read as
   * "match anything", and a name containing an underscore would match any
   * single character. Neither is a security hole — the value is still bound as
   * a parameter, so there is no injection — but both produce baffling results.
   *
   * The backslash is escaped FIRST. Doing it last would double-escape the
   * backslashes introduced by the other two replacements.
   *
   * @param string $value The user-supplied search term.
   * @return string The term with LIKE wildcards escaped.
   */
  private function escapeLike(string $value): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
  }

  /**
   * Finds physicians who were in a previous import but not the most recent one.
   *
   * @param \DateTimeImmutable $lastRun Timestamp of the most recent import run.
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
   *
   * @param \DateTimeImmutable $lastRun Timestamp of the most recent import run.
   * @return int
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
   *
   * @param \DateTimeImmutable $lastRun Timestamp of the most recent import run.
   * @return \Doctrine\ORM\QueryBuilder
   */
  private function departedSinceQueryBuilder(\DateTimeImmutable $lastRun): QueryBuilder {
    return $this->createQueryBuilder('p')
      ->andWhere('p.lastSeenInImportAt IS NOT NULL')
      ->andWhere('p.lastSeenInImportAt < :lastRun')
      ->setParameter('lastRun', $lastRun);
  }
}
