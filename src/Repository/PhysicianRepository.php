<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum\PhysicianVocabulary;
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
   * ranked where it did.
   */
  /*
   * ── The order comes from the specification ────────────────────────────────
   *   Rule: an exact NAME match always ranks highest.
   *   Then: exact specialty → clinical interest → keyword relevance →
   *         location proximity → research relevance.
   *
   * Proximity is applied as a FILTER rather than as a score tier: a radius is
   * a hard boundary — "not within ten miles" means not a result at all, not a
   * result ranked lower — so it narrows the set and the tiers below then rank
   * what remains. Ordering by distance within that set is the caller's choice;
   * see FacilityRepository::near(), which returns nearest-first.
   *
   * Research relevance is a later phase, which is why the gaps below stay wide.
   */

  /** Someone typed the whole name. Beats everything, by rule. */
  public const SCORE_EXACT_FULL    = 1000;

  /** Someone typed just the surname — still an exact name match. */
  public const SCORE_EXACT_SURNAME = 900;

  /** The query IS a specialty: "cardiology" finding every cardiologist. */
  public const SCORE_EXACT_SPECIALTY = 800;

  /** The query IS a clinical interest: "heart failure". */
  public const SCORE_EXACT_INTEREST = 700;

  /* ── Keyword relevance, in descending confidence ────────────────────────── */
  public const SCORE_PREFIX_FULL     = 600; // "anne m. val…"
  public const SCORE_PREFIX_SURNAME  = 550; // "val…" matching Valente
  public const SCORE_CONTAINS        = 500; // "lent" anywhere in the name
  public const SCORE_PARTIAL_SPECIALTY = 400; // "cardio" inside a specialty name
  public const SCORE_PARTIAL_INTEREST  = 350; // "heart" inside an interest

  /**
   * Phonetic — last, and far below everything literal.
   *
   * SOUNDEX is noisy (it returns Khan and Kim for "kohen"), so a phonetic hit
   * must never displace something the user actually typed. The gap is large on
   * purpose: no combination of weaker signals should lift a phonetic match
   * above a literal one.
   */
  public const SCORE_PHONETIC      = 200;

  /** No search term; filters only. Ordering falls back to name. */
  public const SCORE_BROWSE        = 0;

  /**
   * Filters backed by a DEDICATED table — one join table per entity.
   *
   * Only Department and Facility work this way. Everything else is a Term in
   * the shared taxonomy and is derived from PhysicianVocabulary instead, by
   * taxonomyFilters() below — so adding a vocabulary costs an enum case and
   * nothing here.
   */
  private const ENTITY_FILTERS = [
    'department' => ['table' => 'physician_departments', 'column' => 'department_id'],
    'facility'   => ['table' => 'physician_facilities',  'column' => 'facility_id'],
  ];

  /**
   * Every filter this repository understands, keyed by request parameter.
   *
   * ── Why this is built rather than written out ─────────────────────────────
   * The shared-taxonomy filters are all the same shape — the SAME join table
   * and the SAME column — distinguished only by which vocabulary the term
   * belongs to. Listing six of them by hand is six chances for the list to
   * drift from PhysicianVocabulary, and drift here is silent: a filter that
   * exists in the enum but not in this list simply never applies, and the
   * search quietly returns unfiltered results.
   *
   * ── The vocabulary condition is a security boundary, not decoration ───────
   * A term id already implies its vocabulary, so the extra check is redundant
   * for a well-formed request. It is here because the request is not
   * trustworthy: `?specialty=<id of a Language term>` would otherwise filter
   * happily by the wrong vocabulary. It stands in for the foreign key a shared
   * table cannot provide.
   *
   * @return array<string, array{table: string, column: string, vocabulary?: string}>
   */
  private static function taxonomyFilters(): array {
    $filters = self::ENTITY_FILTERS;

    foreach (PhysicianVocabulary::active() as $vocabulary) {
      $filters[$vocabulary->filterKey()] = [
        'table'      => 'physician_terms',
        'column'     => 'term_id',
        'vocabulary' => $vocabulary->value,
      ];
    }

    return $filters;
  }


  /**
   * The taxonomy keys this repository knows how to filter on.
   *
   * Exposed so a controller can read the request without hard-coding the same
   * list a second time — the two would drift the moment a taxonomy is added.
   *
   * @return list<string>
   */
  public static function filterableTaxonomies(): array {
    return array_keys(self::taxonomyFilters());
  }

  /**
   * Searches physicians by free text, with optional filters.
   *
   * The query is matched against the NAME, the SPECIALTY and the CLINICAL
   * INTERESTS together, so "cardiology" finds every cardiologist rather than
   * only someone unfortunate enough to be surnamed Cardiology. Results are
   * ranked by the tiers above, in the order the specification sets out.
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
   * @param string $term Free-text query over name, specialty and interests;
   *   '' browses by filter alone.
   * @param array $taxonomyFilters Keyed by taxonomy — see self::taxonomyFilters().
   * @param ?string $credential Restrict to this PRIMARY credential, e.g. 'MD'.
   * @param int $page The page number for pagination (1-based).
   * @param int $perPage The number of results per page for pagination.
   * @param list<int>|null $nearFacilityIds Restrict to physicians practising at
   *   one of these facilities. null means no proximity filter; an EMPTY array
   *   means a radius was applied and nothing fell inside it, which matches
   *   nobody — the two are not interchangeable.
   * @return array{results: Entity\Physician[], total: int, scores: array<int, int>}
   */
  public function search(
    string $term,
    array $taxonomyFilters = [],
    ?string $credential = null,
    int $page = 1,
    int $perPage = 20,
    ?array $nearFacilityIds = null,
  ): array {
    $term = trim($term);
    $page = max(1, $page);

    $where  = [];
    $params = [];

    // ── Which taxonomy terms does the query itself name? ────────────────────
    // Resolved ONCE, up front, rather than joining vocabulary_terms and running
    // a LIKE against it for every physician. That table holds ~400 rows, so
    // matching it costs almost nothing; doing the same work per physician would
    // multiply it by 10,933.
    //
    // What comes back is four id lists, which the main query then uses through
    // an indexed `term_id IN (…)` — an index lookup rather than a scan.
    $matchedTerms = $term === '' ? null : $this->matchTerms($term);

    // $term is the search query that we're searching for. Obviously we don't
    // run an empty search.
    if ($term !== '') {
      // LOWER() on both sides rather than relying on the column's collation.
      // utf8mb4_general_ci already compares case-insensitively, but stating it
      // explicitly means the behaviour does not silently change if someone
      // later alters the collation to a _bin or _cs variant.
      // A free-text query now reaches beyond the name. Per the specification it
      // searches name, specialty and clinical interest together — so
      // "cardiology" finds every cardiologist, not just anyone surnamed
      // Cardiology.
      //
      // The term clause is only added when the query actually matched
      // something. An empty IN () is a syntax error in MySQL, and building one
      // defensively as `IN (0)` would be a wasted subquery on every search that
      // happens to be a plain name.
      $nameClause = '(LOWER(p.legal_name) LIKE :contains '
        . "OR SOUNDEX(SUBSTRING_INDEX(p.legal_name, ' ', -1)) = SOUNDEX(:raw))";

      $allTermIds = $matchedTerms['all'] ?? [];

      if ($allTermIds !== []) {
        $nameClause = sprintf(
          '(%s OR EXISTS (SELECT 1 FROM physician_terms qt
                           WHERE qt.physician_id = p.id AND qt.term_id IN (%s)))',
          $nameClause,
          // Interpolated because these are ints this method produced from its
          // own query — never request data. Binding an array would need
          // ARRAY_INT type plumbing for no gain.
          implode(',', array_map('intval', $allTermIds)),
        );
      }

      $where[] = $nameClause;

      $params['raw']      = mb_strtolower($term);
      $params['prefix']   = $this->escapeLike(mb_strtolower($term)) . '%';
      $params['contains'] = '%' . $this->escapeLike(mb_strtolower($term)) . '%';
    }

    // Taxonomy filters
    // Driven by self::taxonomyFilters(), which derives the shared-taxonomy
    // entries from PhysicianVocabulary. Adding a vocabulary therefore costs an
    // enum case and nothing here — and cannot drift out of step with the enum,
    // because there is no second list to keep in step.
    $filterDefinitions = self::taxonomyFilters();

    $aliasIndex = 0;
    foreach ($taxonomyFilters as $taxonomy => $id) {
      if ($id === null || !isset($filterDefinitions[$taxonomy])) {
        continue;
      }

      $join        = $filterDefinitions[$taxonomy];
      $alias       = 'tx' . $aliasIndex++;
      $placeholder = 'tx_' . $taxonomy;

      // A vocabulary-qualified filter joins on through the term to its
      // vocabulary, so a term id from the wrong vocabulary matches nothing
      // instead of silently filtering by it.
      $vocabularyJoin = '';
      if (isset($join['vocabulary'])) {
        $vocabularyJoin = sprintf(
          ' AND EXISTS (SELECT 1 FROM vocabulary_terms %1$s_t
            JOIN vocabularies %1$s_v ON %1$s_v.id = %1$s_t.vocabulary_id
            WHERE %1$s_t.id = %1$s.%2$s AND %1$s_v.name = :%3$s_vocab)',
          $alias,
          $join['column'],
          $placeholder,
        );

        $params[$placeholder . '_vocab'] = $join['vocabulary'];
      }

      $where[] = sprintf(
        'EXISTS (SELECT 1 FROM %s %s WHERE %s.physician_id = p.id AND %s.%s = :%s%s)',
        $join['table'],
        $alias,
        $alias,
        $alias,
        $join['column'],
        $placeholder,
        $vocabularyJoin,
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

    // ── Proximity ───────────────────────────────────────────────────────────
    // The caller has already worked out WHICH facilities fall inside the
    // radius — that is a question about twenty rows and belongs in
    // FacilityRepository. By the time it reaches here it is an ordinary
    // "practises at one of these sites" restriction, which is a plain indexed
    // lookup.
    //
    // An EMPTY array is meaningful and must not be confused with null: null is
    // "no proximity filter", while an empty array is "a radius was given and
    // nothing falls inside it", which has to match nobody. Collapsing the two
    // would silently return every physician for a search that should return
    // none.
    if ($nearFacilityIds !== null) {
      if ($nearFacilityIds === []) {
        $where[] = '1 = 0';
      }
      else {
        $where[] = sprintf(
          'EXISTS (SELECT 1 FROM physician_facilities nf
                    WHERE nf.physician_id = p.id AND nf.facility_id IN (%s))',
          implode(',', array_map('intval', $nearFacilityIds)),
        );
      }
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

    // ── The scored page ─────────────────────────────────────────────────────
    // A CASE, so the FIRST matching branch wins and the branches are written in
    // the specification's priority order. That is what enforces "exact name
    // always highest": it is the first test, so nothing below can outrank it,
    // whatever else the physician also matches.
    $scoreSql = $term === ''
      ? (string) self::SCORE_BROWSE
      : sprintf(
        'CASE
            WHEN LOWER(p.legal_name) = :raw THEN %d
            WHEN LOWER(SUBSTRING_INDEX(p.legal_name, \' \', -1)) = :raw THEN %d
            %s
            %s
            WHEN LOWER(p.legal_name) LIKE :prefix THEN %d
            WHEN LOWER(SUBSTRING_INDEX(p.legal_name, \' \', -1)) LIKE :prefix THEN %d
            WHEN LOWER(p.legal_name) LIKE :contains THEN %d
            %s
            %s
            ELSE %d
        END',
        self::SCORE_EXACT_FULL,
        self::SCORE_EXACT_SURNAME,
        $this->termScoreBranch($matchedTerms['exactSpecialty'] ?? [], self::SCORE_EXACT_SPECIALTY),
        $this->termScoreBranch($matchedTerms['exactInterest'] ?? [], self::SCORE_EXACT_INTEREST),
        self::SCORE_PREFIX_FULL,
        self::SCORE_PREFIX_SURNAME,
        self::SCORE_CONTAINS,
        $this->termScoreBranch($matchedTerms['partialSpecialty'] ?? [], self::SCORE_PARTIAL_SPECIALTY),
        $this->termScoreBranch($matchedTerms['partialInterest'] ?? [], self::SCORE_PARTIAL_INTEREST),
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
    // ── One query per to-many association, not one query with all of them ────
    // Joining several to-many collections in a SINGLE query multiplies their
    // rows together: a physician in 8 departments at 10 facilities with 3
    // specialties produces 8 × 10 × 3 = 240 rows on his own, and the page's
    // total grows as a product. Running one query per association makes the
    // cost a SUM instead — 8 + 10 + 3 — which stays predictable however many
    // taxonomies get added later.
    //
    // Each query returns the same physicians. Doctrine's identity map hands
    // back the entities already in memory and simply populates the collection
    // that query joined, so three queries produce one fully-hydrated set. Only
    // the first needs to select the physicians themselves.
    $physicians = $this->createQueryBuilder('p')
      ->addSelect('d')
      ->leftJoin('p.departments', 'd')
      ->where('p.id IN (:ids)')
      ->setParameter('ids', $ids)
      ->getQuery()
      ->getResult();

    $this->createQueryBuilder('p')
      ->addSelect('f')
      ->leftJoin('p.facilities', 'f')
      ->where('p.id IN (:ids)')
      ->setParameter('ids', $ids)
      ->getQuery()
      ->getResult();

    // Terms join through to their vocabulary as well. Without that second hop
    // a template asking "is this a specialty?" would lazy-load the vocabulary
    // once PER TERM — trading the N+1 on physicians for a worse one on terms.
    $this->createQueryBuilder('p')
      ->addSelect('t', 'tv')
      ->leftJoin('p.terms', 't')
      ->leftJoin('t.vocabulary', 'tv')
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
   * Suggestions for a partially-typed query.
   *
   * ── Suggests what search actually matches ─────────────────────────────────
   * Names, specialties and clinical interests — the same three things a
   * free-text query is scored against. A typeahead that offers something the
   * search cannot then find is worse than none at all, because it teaches the
   * user to trust it.
   *
   * That is also why the suggestion is a plain STRING rather than a link to a
   * pre-filtered URL: picking one fills the search box, and searching for it
   * hits the exact-specialty or exact-interest tier, which is precisely the
   * result the user wanted. The typeahead and the ranking reinforce each other
   * instead of being two separate notions of relevance.
   *
   * ── Ordering ──────────────────────────────────────────────────────────────
   * Taxonomy terms first, then names. A specialty is a broad, useful query;
   * a single physician's name is narrow, and someone typing "car" is far more
   * likely to be looking for cardiology than for Dr Carmichael. Within each
   * group, prefix matches come before substring matches, because the thing you
   * are part-way through typing is more likely to start with what you typed.
   *
   * @return list<array{value: string, kind: string}>
   */
  public function suggest(string $term, int $limit = 10): array {
    $term = trim($term);

    // Two characters is the floor. One character matches most of the table and
    // suggests nothing useful, while costing a scan on every keystroke.
    if (mb_strlen($term) < 2) {
      return [];
    }

    $lower    = mb_strtolower($term);
    $prefix   = $this->escapeLike($lower) . '%';
    $contains = '%' . $this->escapeLike($lower) . '%';

    $connection = $this->entityManager->getConnection();

    // ── Taxonomy terms ──────────────────────────────────────────────────────
    // Only vocabularies that free text is scored against. Language and
    // department are filters rather than keywords, so suggesting them here
    // would produce a query that matches nothing.
    $terms = $connection->executeQuery(
      "SELECT t.name AS value, v.name AS kind,
              (LOWER(t.name) LIKE :prefix) AS is_prefix
         FROM vocabulary_terms t
         JOIN vocabularies v ON v.id = t.vocabulary_id
        WHERE v.name IN (:vocabularies)
          AND LOWER(t.name) LIKE :contains
        ORDER BY is_prefix DESC, CHAR_LENGTH(t.name) ASC, t.name ASC
        LIMIT :limit",
      [
        'prefix'       => $prefix,
        'contains'     => $contains,
        'limit'        => $limit,
        // The SAME set free text is scored against, from the same source of
        // truth. If these two lists could differ, the typeahead would sooner or
        // later offer a term that search cannot match — and one suggestion that
        // leads nowhere costs more trust than ten good ones earn.
        'vocabularies' => array_map(
          static fn (PhysicianVocabulary $v): string => $v->value,
          PhysicianVocabulary::searchable(),
        ),
      ],
      [
        'limit'        => ParameterType::INTEGER,
        'vocabularies' => ArrayParameterType::STRING,
      ],
    )->fetchAllAssociative();

    $suggestions = [];
    foreach ($terms as $row) {
      $suggestions[] = ['value' => (string) $row['value'], 'kind' => (string) $row['kind']];
    }

    // ── Physician names ─────────────────────────────────────────────────────
    // Only the remaining slots, so a query that is clearly a specialty does not
    // waste half the list on coincidental name matches.
    $remaining = $limit - count($suggestions);

    if ($remaining > 0) {
      $names = $connection->executeQuery(
        "SELECT DISTINCT p.legal_name AS value,
                (LOWER(p.legal_name) LIKE :prefix
                 OR LOWER(SUBSTRING_INDEX(p.legal_name, ' ', -1)) LIKE :prefix) AS is_prefix
           FROM physicians p
          WHERE LOWER(p.legal_name) LIKE :contains
          ORDER BY is_prefix DESC, p.legal_name ASC
          LIMIT :limit",
        ['prefix' => $prefix, 'contains' => $contains, 'limit' => $remaining],
        ['limit' => ParameterType::INTEGER],
      )->fetchAllAssociative();

      foreach ($names as $row) {
        $suggestions[] = ['value' => (string) $row['value'], 'kind' => 'physician'];
      }
    }

    return $suggestions;
  }

  /**
   * The most common specialties, for seeding the suggestion list.
   *
   * Rendered into the page's <datalist> so that a visitor with no JavaScript
   * still gets native autocomplete on the queries most likely to be useful.
   * The dynamic endpoint replaces these once scripting is available.
   *
   * @return list<string>
   */
  public function commonSpecialties(int $limit = 30): array {
    return $this->entityManager->getConnection()->executeQuery(
      "SELECT t.name
         FROM vocabulary_terms t
         JOIN vocabularies v ON v.id = t.vocabulary_id
         JOIN physician_terms pt ON pt.term_id = t.id
        WHERE v.name = :vocabulary
        GROUP BY t.id
        ORDER BY COUNT(pt.physician_id) DESC, t.name ASC
        LIMIT :limit",
      ['vocabulary' => PhysicianVocabulary::Specialty->value, 'limit' => $limit],
      ['limit' => ParameterType::INTEGER],
    )->fetchFirstColumn();
  }

  /**
   * Finds the taxonomy terms a free-text query names.
   *
   * ── Why this is a separate, up-front query ────────────────────────────────
   * The alternative is joining vocabulary_terms into the main search and
   * running a LIKE against it per physician. vocabulary_terms holds a few
   * hundred rows, so matching it once costs almost nothing — doing it per
   * physician multiplies that by 10,933 for no additional information.
   *
   * Resolving to ids first also means the main query can use `term_id IN (…)`
   * against an indexed column, which is a lookup rather than a scan.
   *
   * ── Exact and partial are kept apart ──────────────────────────────────────
   * They score differently and the specification treats them differently: an
   * exact specialty match is a strong signal ("cardiology" — this person IS a
   * cardiologist), a partial one is weak ("cardio" — might be Cardiology, might
   * be Cardiothoracic Surgery). Collapsing them would rank a substring hit as
   * highly as someone typing the specialty's full name.
   *
   * @return array{
   *   exactSpecialty: list<int>, partialSpecialty: list<int>,
   *   exactInterest: list<int>,  partialInterest: list<int>,
   *   all: list<int>,
   * }
   */
  private function matchTerms(string $term): array {
    $lower = mb_strtolower(trim($term));

    $rows = $this->entityManager->getConnection()->executeQuery(
      'SELECT t.id, v.name AS vocabulary, LOWER(t.name) = :raw AS is_exact
         FROM vocabulary_terms t
         JOIN vocabularies v ON v.id = t.vocabulary_id
        WHERE v.name IN (:vocabularies)
          AND LOWER(t.name) LIKE :contains',
      [
        'raw'          => $lower,
        'contains'     => '%' . $this->escapeLike($lower) . '%',
        // Which vocabularies free text reaches is the enum's decision, not this
        // method's — see PhysicianVocabulary::isFreeTextSearchable(). Language
        // and board certification are filters, deliberately excluded.
        'vocabularies' => array_map(
          static fn (PhysicianVocabulary $v): string => $v->value,
          PhysicianVocabulary::searchable(),
        ),
      ],
      ['vocabularies' => ArrayParameterType::STRING],
    )->fetchAllAssociative();

    $matched = [
      'exactSpecialty'   => [],
      'partialSpecialty' => [],
      'exactInterest'    => [],
      'partialInterest'  => [],
      'all'              => [],
    ];

    foreach ($rows as $row) {
      $id      = (int) $row['id'];
      $isExact = (bool) $row['is_exact'];

      $bucket = match (true) {
        $row['vocabulary'] === PhysicianVocabulary::Specialty->value && $isExact  => 'exactSpecialty',
        $row['vocabulary'] === PhysicianVocabulary::Specialty->value              => 'partialSpecialty',
        $isExact                                                                  => 'exactInterest',
        default                                                                   => 'partialInterest',
      };

      $matched[$bucket][] = $id;
      $matched['all'][]   = $id;
    }

    return $matched;
  }

  /**
   * One CASE branch testing whether a physician holds any of these terms.
   *
   * Returns an empty string when the list is empty, which removes the branch
   * from the CASE entirely — both because `IN ()` is a syntax error in MySQL
   * and because a branch that can never match is a subquery evaluated for
   * nothing on every row.
   *
   * @param list<int> $termIds
   */
  private function termScoreBranch(array $termIds, int $score): string {
    if ($termIds === []) {
      return '';
    }

    return sprintf(
      'WHEN EXISTS (SELECT 1 FROM physician_terms st
                     WHERE st.physician_id = p.id AND st.term_id IN (%s)) THEN %d',
      // Ints from this class's own query, never from the request.
      implode(',', array_map('intval', $termIds)),
      $score,
    );
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
