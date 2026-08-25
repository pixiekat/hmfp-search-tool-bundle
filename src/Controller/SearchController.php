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

  public function __construct(
    private readonly PixieHelperServices\AuditLogManager $auditLogManager,
    private readonly Repository\PhysicianRepository $physicians,
    private readonly Repository\DepartmentRepository $departments,
    private readonly Repository\FacilityRepository $facilities,
    private readonly PhysicianTaxonomyManager $taxonomy,
    private readonly CacheInterface $cache,
  ) {  }

  #[IsGranted(Interfaces\Security\Voter\SearchVoterInterface::PERMISSION_CAN_ACCESS_SEARCH)]
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

    // Did the user actually ask for anything? If not, don't run the search and don't log it.
    $activeFilters = array_filter($taxonomyFilters, static fn (?int $id): bool => $id !== null);
    $hasCriteria   = $term !== '' || $activeFilters !== [] || $credential !== null;

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
      ]);
    }

    return $this->render('@HMFPSearchTool/search/search.html.twig', [
      // What was asked. The individual ids are also exposed under their own
      // names so a template can mark the right <option> selected without
      // digging into the array — a convenience that costs nothing and keeps
      // the Twig readable.
      'term'         => $term,
      'filters'      => $taxonomyFilters,
      'departmentId' => $taxonomyFilters['department'] ?? null,
      'facilityId'   => $taxonomyFilters['facility'] ?? null,
      'credential'   => $credential,
      'hasCriteria'  => $hasCriteria,
      'activeFilterCount' => count($activeFilters) + ($credential !== null ? 1 : 0),

      // What came back
      'physicians' => $results,
      'total'      => $total,
      'scores'     => $scores,
      'page'       => $page,
      'pages'      => $pages,
      'perPage'    => self::PER_PAGE,

      // Vocabularies for the filter controls — see cachedCredentials() below
      // for why only one of these three is cached.
      //
      // findBy() with an explicit sort rather than a custom repository method:
      // a dropdown needs the rows in alphabetical order and nothing else, and
      // depending on a hand-written finder here would break the search page
      // any time one was renamed or removed.
      'departmentOptions' => $this->departments->findBy([], ['name' => 'ASC']),
      'facilityOptions'   => $this->facilities->findBy([], ['name' => 'ASC']),
      'credentialOptions' => $this->cachedCredentials(),

      // Shared-taxonomy options. Unlike the two above these are Terms, so they
      // must be narrowed to one vocabulary — vocabulary_terms holds every
      // taxonomy at once, and an unfiltered read would offer languages and
      // conditions in the specialty dropdown.
      'specialtyOptions'  => $this->taxonomy->termsByName(PhysicianVocabulary::Specialty),
      'specialtyId'       => $taxonomyFilters['specialty'] ?? null,
      'languageOptions'   => $this->taxonomy->termsByName(PhysicianVocabulary::Language),
      'languageId'        => $taxonomyFilters['language'] ?? null,
      'clinicalInterestOptions' => $this->taxonomy->termsByName(PhysicianVocabulary::ClinicalInterest),
      'clinicalInterestId'      => $taxonomyFilters['clinical_interest'] ?? null,

      // Seeds the <datalist> so native autocomplete works with NO JavaScript at
      // all. The suggest endpoint replaces these once scripting is available;
      // without it, the visitor still gets the queries most likely to be useful.
      'suggestionSeed' => $this->cachedCommonSpecialties(),
    ]);
  }

  /**
   * Suggestions for the search box.
   *
   * ── Same repository method the page uses ──────────────────────────────────
   * PhysicianRepository::suggest() draws from the same names, specialties and
   * interests that free-text search is scored against. A typeahead backed by a
   * different notion of relevance eventually offers something the search cannot
   * find, and one bad suggestion costs more trust than ten good ones earn.
   *
   * ── Why this returns strings and not links ────────────────────────────────
   * Picking a suggestion fills the search box; it does not navigate. That keeps
   * the enhanced flow identical to the unenhanced one — you still press Search,
   * still land on a normal results URL — and it is what allows the whole thing
   * to be built on <datalist>, where the browser owns the interaction.
   *
   * Not cached: the response varies per keystroke, so a cache would hold
   * thousands of one-shot entries. The query itself is a couple of indexed
   * LIKEs over small tables.
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

    // Private, because the route is behind authentication — a shared cache must
    // not hand one user's suggestions to another. Short-lived because the
    // underlying vocabulary changes only on import or approval, so a few
    // seconds of staleness costs nothing while absorbing the burst of requests
    // a fast typist generates.
    $response->setPrivate();
    $response->setMaxAge(30);

    return $response;
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
