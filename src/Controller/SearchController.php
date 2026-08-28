<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Controller;

use Pixiekat\HMFPSearchToolBundle\Interfaces;
use Pixiekat\HMFPSearchToolBundle\Enum\PhysicianVocabulary;
use Pixiekat\HMFPSearchToolBundle\Repository;
use Pixiekat\HMFPSearchToolBundle\Services\PhysicianTaxonomyManager;
use Pixiekat\SymfonyHelpers\Services as PixieHelperServices;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Public provider search.
 *
 * @see \Pixiekat\HMFPSearchToolBundle\Repository\PhysicianRepository::search() for the
 * actual search implementation. This controller is only responsible for reading the
 * request, passing it to the repository, and rendering the results.
 */
final class SearchController extends AbstractController {

  /**
   * Results per page.
   *
   * Twenty is a deliberate compromise: enough that the common case ("find one
   * person") is usually answered on page one, few enough that the eager-joined
   * hydration in search() stays cheap and the page stays navigable by keyboard
   * without endless tabbing.
   */
  private const PER_PAGE = 20;

  /**
   * The longest query string we will act on.
   *
   * Not a security control — the value is parameterised either way — but a
   * 10,000-character "name" is never a real search, and refusing to build a
   * LIKE pattern out of one keeps the scan predictable.
   */
  private const MAX_TERM_LENGTH = 100;

  /**
   * How long the filter dropdowns stay cached.
   *
   * The vocabularies behind them — departments, facilities, credentials —
   * change only when an import runs, which is a scheduled event measured in
   * days. An hour is therefore very conservative; it exists so that a manual
   * edit in the admin UI shows up without anyone needing to clear a cache.
   */
  private const VOCABULARY_TTL = 3600;

  /**
   * How many suggestions the endpoint returns.
   *
   * Ten is about what fits in a native datalist dropdown without scrolling. A
   * longer list is not more helpful — if the right answer is not near the top,
   * the user types another character instead of reading forty options.
   */
  private const SUGGESTION_LIMIT = 10;

  /**
   * How many specialties seed the no-JavaScript datalist.
   *
   * Larger than SUGGESTION_LIMIT because the browser filters this list itself
   * as the user types, so it is a corpus rather than a result set.
   */
  private const SEED_LIMIT = 30;

  /**
   * Radii offered by the distance filter, in miles.
   *
   * A fixed list rather than a free number field. It keeps the control simple,
   * it stops someone asking for a 4,000-mile radius, and every value here is a
   * distance a patient would actually consider travelling for an appointment.
   */
  private const RADIUS_OPTIONS = [1, 5, 10, 25, 50];

  /** Used when ?near is given without a radius. */
  private const DEFAULT_RADIUS_MILES = 10;

  public function __construct(
    private readonly PixieHelperServices\AuditLogManager $auditLogManager,
    private readonly Repository\PhysicianRepository $physicians,
    private readonly Repository\DepartmentRepository $departments,
    private readonly Repository\FacilityRepository $facilities,
    private readonly PhysicianTaxonomyManager $taxonomy,
    private readonly Repository\SearchEventRepository $searchEvents,
    private readonly CacheInterface $cache,
  ) {  }

  #[IsGranted(Interfaces\Security\Voter\SearchVoterInterface::PERMISSION_CAN_ACCESS_SEARCH)]
  #[Route('/', name: 'hmfp_search_tool_home', methods: ['GET'])]
  #[Route('/', name: '<front>', methods: ['GET'])]
  #[Route('/', name: 'hmfp_search_tool_home_front', methods: ['GET'])]
  #[Route('/search', name: 'hmfp_search_tool_search', methods: ['GET'])]
  public function search(Request $request): Response {
    // get the initial search term from the request and clean it.
    $term = $this->queryString($request, 'q');
    if (mb_strlen($term) > self::MAX_TERM_LENGTH) {
      $term = mb_substr($term, 0, self::MAX_TERM_LENGTH);
    }

    /**
     * Get the taxonomy filters.
     *
     * @see \Pixiekat\HMFPSearchToolBundle\Repository\PhysicianRepository::filterableTaxonomies() for the list of filterable taxonomies.
     */
    $taxonomyFilters = [];
    foreach (Repository\PhysicianRepository::filterableTaxonomies() as $taxonomy) {
      $taxonomyFilters[$taxonomy] = $this->queryId($request, $taxonomy);
    }

    $credential = $this->queryString($request, 'credential') ?: null;
    $page       = $this->queryId($request, 'page') ?? 1;

    // Proximity:
    // The user can ask for a radius search around a facility. The facility is the center
    // point, and the radius is in miles. The controller resolves the facility to
    // coordinates and then finds all facilities within that radius. Those facilities
    // are then used to filter the physician search results.
    $nearFacilityId = $this->queryId($request, 'near');
    $radius         = $this->queryId($request, 'radius') ?? self::DEFAULT_RADIUS_MILES;

    if (!in_array($radius, self::RADIUS_OPTIONS, true)) {
      $radius = self::DEFAULT_RADIUS_MILES;
    }

    $nearFacility    = $nearFacilityId === null ? null : $this->facilities->find($nearFacilityId);
    $nearFacilityIds = null;
    $nearResults     = [];

    if ($nearFacility !== null && $nearFacility->hasCoordinates()) {
      $nearResults = $this->facilities->near(
        (float) $nearFacility->getLatitude(),
        (float) $nearFacility->getLongitude(),
        (float) $radius,
      );

      // Note this is an ARRAY, possibly empty — never null — once a usable
      // centre exists. Empty means "a radius was applied and nothing fell
      // inside it", which must match nobody; null would mean "no proximity
      // filter" and match everybody. See search().
      $nearFacilityIds = array_map(
        static fn (array $hit): int => $hit['facility']->getId(),
        $nearResults,
      );
    }

    // Did the user actually ask for anything? If not, don't run the search and don't log it.
    $activeFilters = array_filter($taxonomyFilters, static fn (?int $id): bool => $id !== null);
    $hasCriteria   = $term !== '' || $activeFilters !== [] || $credential !== null || $nearFacilityIds !== null;

    $results = [];
    $total   = 0;
    $scores  = [];
    $pages   = 0;

    if ($hasCriteria) {
      $found = $this->physicians->search(
        term: $term,
        taxonomyFilters: $taxonomyFilters,
        credential: $credential,
        page: $page,
        perPage: self::PER_PAGE,
        nearFacilityIds: $nearFacilityIds,
      );

      $results = $found['results'];
      $total   = $found['total'];
      $scores  = $found['scores'];
      $pages   = (int) ceil($total / self::PER_PAGE);

      // Log to audit log monolog channel; we wan't to debug search issues but we don't need the
      // overhead of writing to the database for every search.
      $this->auditLogManager->logToLogger('search.performed', null, [
        'term'        => $term,
        'filters'     => $taxonomyFilters,
        'credential'  => $credential,
        'resultCount' => $total,
        'page'        => $page,
      ], \Psr\Log\LogLevel::DEBUG);

      // Analytics: Track a search event for analytics purposes. This is a seperate
      // call from the log.
      if ($page === 1) {
        $this->searchEvents->record(
          term: $term === '' ? null : mb_strtolower($term),
          resultCount: $total,
          filtersUsed: $this->appliedFilterKeys($taxonomyFilters, $credential, $nearFacilityIds),
          matchedTermId: $found['exactMatch']['id'] ?? null,
          matchedTermName: $found['exactMatch']['name'] ?? null,
          matchedVocabulary: $found['exactMatch']['vocabulary'] ?? null,
        );
      }
    }

    return $this->render('@HMFPSearchTool/search/search.html.twig', [
      // results
      'term'         => $term,
      'filters'      => $taxonomyFilters,
      'departmentId' => $taxonomyFilters['department'] ?? null,
      'facilityId'   => $taxonomyFilters['facility'] ?? null,
      'specialtyId'  => $taxonomyFilters['specialty'] ?? null,
      'credential'   => $credential,
      'hasCriteria'  => $hasCriteria,
      'activeFilterCount' => count($activeFilters)
        + ($credential !== null ? 1 : 0)
        + ($nearFacilityIds !== null ? 1 : 0),

      // proximity
      'nearFacilityId' => $nearFacilityId,
      'nearFacility'   => $nearFacility,
      'radius'         => $radius,
      'radiusOptions'  => self::RADIUS_OPTIONS,
      'nearbyCount'    => count($nearResults),
      // only facilities that have been placed can anchor a distance search.
      'placedFacilities' => $this->facilities->findPlaced(),

      // What came back
      'physicians' => $results,
      'total'      => $total,
      'scores'     => $scores,
      'page'       => $page,
      'pages'      => $pages,
      'perPage'    => self::PER_PAGE,

      // vocabularies for the filter controls — see cachedCredentials() below
      // for why only one of these three is cached.
      'departmentOptions' => $this->departments->findBy([], ['name' => 'ASC']),
      'facilityOptions'   => $this->facilities->findBy([], ['name' => 'ASC']),
      'credentialOptions' => $this->cachedCredentials(),

      // Shared-taxonomy options. Unlike the two above these are Terms, so they
      // must be narrowed to one vocabulary — vocabulary_terms holds every
      // taxonomy at once, and an unfiltered read would offer languages and
      // conditions in the specialty dropdown.
      'taxonomyFilterGroups' => $this->taxonomyFilterGroups($taxonomyFilters),

      // Seeds the <datalist> so native autocomplete works with NO JavaScript at
      // all. The suggest endpoint replaces these once scripting is available;
      // without it, the visitor still gets the queries most likely to be useful.
      'suggestionSeed' => $this->cachedCommonSpecialties(),
    ]);
  }

  /**
   * Suggestions for the search box.
   */
  #[IsGranted(Interfaces\Security\Voter\SearchVoterInterface::PERMISSION_CAN_ACCESS_SEARCH)]
  #[Route('/search/suggest', name: 'hmfp_search_tool_search_suggest', methods: ['GET'])]
  public function suggest(Request $request): JsonResponse {
    $term = $this->queryString($request, 'q');

    if (mb_strlen($term) > self::MAX_TERM_LENGTH) {
      $term = mb_substr($term, 0, self::MAX_TERM_LENGTH);
    }

    $suggestions = $this->physicians->suggest($term, self::SUGGESTION_LIMIT);

    $response = new JsonResponse([
      'query'       => $term,
      'suggestions' => $suggestions,
    ]);

    // private, because the route is behind authentication — a shared cache must
    // not hand one user's suggestions to another. Short-lived because the
    // underlying vocabulary changes only on import or approval, so a few
    // seconds of staleness costs nothing while absorbing the burst of requests
    // a fast typist generates.
    $response->setPrivate();
    $response->setMaxAge(30);

    return $response;
  }

  /**
   * Which filters a search actually applied, as plain keys.
   *
   * Names rather than a count, because the question worth asking is "is anyone
   * using the language filter?" — which a total cannot answer.
   *
   * @param array<string, int|null> $taxonomyFilters
   * @param list<int>|null          $nearFacilityIds
   *
   * @return list<string>
   */
  private function appliedFilterKeys(array $taxonomyFilters, ?string $credential, ?array $nearFacilityIds): array {
    $keys = array_keys(array_filter($taxonomyFilters, static fn (?int $id): bool => $id !== null));

    if ($credential !== null) {
      $keys[] = 'credential';
    }

    if ($nearFacilityIds !== null) {
      $keys[] = 'near';
    }

    return array_values($keys);
  }

  /**
   * Filter controls for every shared-taxonomy vocabulary that has terms.
   *
   * @param array<string, int|null> $taxonomyFilters
   *
   * @return list<array{key: string, label: string, anyLabel: string, options: list<object>, selected: int|null}>
   */
  private function taxonomyFilterGroups(array $taxonomyFilters): array {
    $groups = [];

    foreach (PhysicianVocabulary::active() as $vocabulary) {
      $options = array_values($this->taxonomy->termsByName($vocabulary));

      if ($options === []) {
        continue;
      }

      usort($options, static fn ($a, $b): int => strcasecmp((string) $a->getName(), (string) $b->getName()));

      $groups[] = [
        'key'      => $vocabulary->filterKey(),
        'label'    => $vocabulary->label(),
        'anyLabel' => $vocabulary->anyOptionLabel(),
        'options'  => $options,
        'selected' => $taxonomyFilters[$vocabulary->filterKey()] ?? null,
      ];
    }

    return $groups;
  }

  /**
   * The datalist seed, cached alongside the credential options.
   *
   * Same reasoning as cachedCredentials(): this aggregates over
   * physician_terms to rank specialties by how many providers hold them, which
   * is real work to produce a list that changes only when an import runs.
   *
   * @return list<string>
   */
  private function cachedCommonSpecialties(): array {
    return $this->cache->get(
      'hmfp_search.common_specialties',
      function (ItemInterface $item): array {
        $item->expiresAfter(self::VOCABULARY_TTL);

        return $this->physicians->commonSpecialties(self::SEED_LIMIT);
      },
    );
  }

  /**
   * Reads an optional positive integer from the query string, tolerantly.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request The request object
   * @param string $key The query string key to read
   * @return int|null The positive integer value, or null if not present or invalid
   */
  private function queryId(Request $request, string $key): ?int {
    $raw = $request->query->all()[$key] ?? null;

    if (!is_string($raw)) {
      return null;
    }

    $raw = trim($raw);

    // ctype_digit rejects "-1", "1.5", "1e3" and "" in one test, so only a
    // plain positive integer survives.
    if ($raw === '' || !ctype_digit($raw)) {
      return null;
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null;
  }

  /**
   * Reads an optional trimmed string from the query string, tolerantly.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request The request object
   * @param string $key The query string key to read
   * @return string The trimmed string value, or an empty string if not present
   */
  private function queryString(Request $request, string $key): string {
    $raw = $request->query->all()[$key] ?? null;

    return is_string($raw) ? trim($raw) : '';
  }

  /**
   * The credential dropdown's options, cached.
   *
   * @return list<array{credential: string, total: int}>
   */
  private function cachedCredentials(): array {
    return $this->cache->get(
      'hmfp_search.primary_credentials',
      function (ItemInterface $item): array {
        $item->expiresAfter(self::VOCABULARY_TTL);

        return $this->physicians->findPrimaryCredentials();
      },
    );
  }
}
