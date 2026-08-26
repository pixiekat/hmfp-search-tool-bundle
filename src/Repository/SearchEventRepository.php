<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Psr\Log\LoggerInterface;

/**
 * Records searches and answers aggregate questions about them.
 *
 * @extends ServiceEntityRepository<Entity\SearchEvent>
 */
class SearchEventRepository extends ServiceEntityRepository {

  public function __construct(
    ManagerRegistry $registry,
    private EntityManagerInterface $entityManager,
    private LoggerInterface $logger,
  ) {
    parent::__construct($registry, Entity\SearchEvent::class);
  }

  /**
   * Records one search. Never throws.
   *
   * @param list<string> $filtersUsed
   */
  public function record(
    ?string $term,
    int $resultCount = 0,
    array $filtersUsed = [],
    ?int $matchedTermId = null,
    ?string $matchedTermName = null,
    ?string $matchedVocabulary = null,
    ?string $correlationId = null,
  ): void {
    try {
      $this->entityManager->getConnection()->insert('search_events', [
        'occurred_at'        => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        'term'               => $term,
        'matched_term_id'    => $matchedTermId,
        'matched_term_name'  => $matchedTermName,
        'matched_vocabulary' => $matchedVocabulary,
        'result_count'       => $resultCount,
        'filters_used'       => $filtersUsed === [] ? null : implode(',', $filtersUsed),
        'correlation_id'     => $correlationId ?? bin2hex(random_bytes(16)),
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to record search event: {message}', [
        'message'   => $e->getMessage(),
        'exception' => $e,
      ]);
    }
  }

  /**
   * The terms searched most often in a period.
   *
   * Filter-only browses are excluded — they have no term, and counting them
   * as a single enormous "null" entry would crowd out everything real.
   *
   * @return list<array{term: string, searches: int, avg_results: float, zero_results: int}>
   */
  public function topTerms(\DateTimeImmutable $since, int $limit = 20): array {
    return $this->connection()->executeQuery(
      'SELECT term,
              COUNT(*) AS searches,
              ROUND(AVG(result_count), 1) AS avg_results,
              SUM(result_count = 0) AS zero_results
         FROM search_events
        WHERE occurred_at >= :since AND term IS NOT NULL
        GROUP BY term
        ORDER BY searches DESC, term ASC
        LIMIT :limit',
      ['since' => $since->format('Y-m-d H:i:s'), 'limit' => $limit],
      ['limit' => ParameterType::INTEGER],
    )->fetchAllAssociative();
  }

  /**
   * The taxonomy terms people searched for BY NAME — "top specialties searched".
   *
   * Grouped on the snapshotted name rather than the id, so a term that has
   * since been renamed or removed still appears under what it was called when
   * people searched for it. Grouping on the id would silently merge a renamed
   * term's history with its replacement's.
   *
   * @return list<array{matched_term_name: string, matched_vocabulary: string, searches: int}>
   */
  public function topMatchedTerms(\DateTimeImmutable $since, int $limit = 20): array {
    return $this->connection()->executeQuery(
      'SELECT matched_term_name, matched_vocabulary, COUNT(*) AS searches
         FROM search_events
        WHERE occurred_at >= :since AND matched_term_id IS NOT NULL
        GROUP BY matched_term_name, matched_vocabulary
        ORDER BY searches DESC, matched_term_name ASC
        LIMIT :limit',
      ['since' => $since->format('Y-m-d H:i:s'), 'limit' => $limit],
      ['limit' => ParameterType::INTEGER],
    )->fetchAllAssociative();
  }

  /**
   * Searches that found nothing — the most actionable list here.
   *
   * Every row is a question the directory failed to answer. Read as a to-do
   * list: a term appearing often with no results is either a taxonomy gap, a
   * spelling the matcher should handle, or a provider who genuinely is not
   * here.
   *
   * @return list<array{term: string, searches: int}>
   */
  public function zeroResultTerms(\DateTimeImmutable $since, int $limit = 20): array {
    return $this->connection()->executeQuery(
      'SELECT term, COUNT(*) AS searches
         FROM search_events
        WHERE occurred_at >= :since AND term IS NOT NULL AND result_count = 0
        GROUP BY term
        ORDER BY searches DESC, term ASC
        LIMIT :limit',
      ['since' => $since->format('Y-m-d H:i:s'), 'limit' => $limit],
      ['limit' => ParameterType::INTEGER],
    )->fetchAllAssociative();
  }

  /**
   * Headline numbers for a period.
   *
   * `zero_result_rate` is the closest thing to a search success rate that this
   * table can answer ALONE — and it is deliberately named for what it measures
   * rather than what it approximates. A search returning results is not proof
   * anyone found what they wanted; proving that needs click-through, which
   * needs the click events to join on correlation_id. Calling this "success
   * rate" would be claiming more than the data supports.
   *
   * @return array{searches: int, with_term: int, filter_only: int, zero_results: int, zero_result_rate: float}
   */
  public function summary(\DateTimeImmutable $since): array {
    $row = $this->connection()->executeQuery(
      'SELECT COUNT(*) AS searches,
              SUM(term IS NOT NULL) AS with_term,
              SUM(term IS NULL) AS filter_only,
              SUM(result_count = 0) AS zero_results
         FROM search_events
        WHERE occurred_at >= :since',
      ['since' => $since->format('Y-m-d H:i:s')],
    )->fetchAssociative() ?: [];

    $searches = (int) ($row['searches'] ?? 0);

    return [
      'searches'         => $searches,
      'with_term'        => (int) ($row['with_term'] ?? 0),
      'filter_only'      => (int) ($row['filter_only'] ?? 0),
      'zero_results'     => (int) ($row['zero_results'] ?? 0),
      'zero_result_rate' => $searches === 0 ? 0.0 : round((int) $row['zero_results'] * 100 / $searches, 1),
    ];
  }

  /**
   * How often each filter is used — is anyone actually using them?
   *
   * @return list<array{filter: string, searches: int}>
   */
  public function filterUsage(\DateTimeImmutable $since): array {
    $rows = $this->connection()->executeQuery(
      'SELECT filters_used FROM search_events
        WHERE occurred_at >= :since AND filters_used IS NOT NULL',
      ['since' => $since->format('Y-m-d H:i:s')],
    )->fetchFirstColumn();

    // Counted in PHP because the column holds a list. A SQL solution needs
    // either a recursive CTE to split the string or a JSON column, and neither
    // is worth it for a report over a column that is only ever read here.
    $counts = [];
    foreach ($rows as $list) {
      foreach (explode(',', (string) $list) as $filter) {
        $filter = trim($filter);
        if ($filter !== '') {
          $counts[$filter] = ($counts[$filter] ?? 0) + 1;
        }
      }
    }

    arsort($counts);

    return array_map(
      static fn (string $f, int $n): array => ['filter' => $f, 'searches' => $n],
      array_keys($counts),
      $counts,
    );
  }

  /**
   * Deletes raw events older than a cutoff.
   *
   * Raw rows are for investigating recent behaviour; anything older is a
   * question for a rollup. Returns the number removed so a scheduled caller can
   * report it.
   */
  public function prune(\DateTimeImmutable $before): int {
    return (int) $this->connection()->executeStatement(
      'DELETE FROM search_events WHERE occurred_at < :before',
      ['before' => $before->format('Y-m-d H:i:s')],
    );
  }

  private function connection(): \Doctrine\DBAL\Connection {
    return $this->entityManager->getConnection();
  }
}
