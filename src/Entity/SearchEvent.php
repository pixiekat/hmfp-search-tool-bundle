<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Pixiekat\HMFPSearchToolBundle\Repository\SearchEventRepository;

/**
 * One search, recorded for aggregate reporting.
 */
#[ORM\Entity(repositoryClass: SearchEventRepository::class)]
#[ORM\Table(name: 'search_events')]
//Every report is "what happened between these dates", so occurred_at leads each index.
#[ORM\Index(name: 'IDX_SEARCH_EVENT_TIME', columns: ['occurred_at'])]
#[ORM\Index(name: 'IDX_SEARCH_EVENT_TERM', columns: ['occurred_at', 'term'])]
#[ORM\Index(name: 'IDX_SEARCH_EVENT_MATCH', columns: ['occurred_at', 'matched_term_id'])]
class SearchEvent {

  /**
   * BIGINT rather than INT.
   *
   * A busy directory can produce millions of these over a few years, and an
   * INT runs out at 2.1 billion. The cost of being wrong is an outage at an
   * arbitrary future moment; the cost of being right is four bytes a row.
   */
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column(type: 'bigint')]
  private ?int $id = null;

  #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
  private \DateTimeImmutable $occurredAt;

  /**
   * The query, lower-cased and trimmed. Null when someone browsed by filter
   * alone.
   *
   * Normalised on the way in because every report groups by it — "Cardiology"
   * and "cardiology" are one search, and storing them separately means every
   * query has to remember to fold them. The original casing carries no
   * analytic value; the Monolog line still has it if a specific complaint ever
   * needs chasing.
   */
  #[ORM\Column(type: 'string', length: 100, nullable: true)]
  private ?string $term = null;

  /**
   * The term the query named EXACTLY, if it named one.
   *
   * This is what makes "top specialties searched" answerable. A query can
   * partially match dozens of terms — "cardio" touches every cardiology
   * specialty — and counting those would tell you nothing. An exact match is
   * an unambiguous statement of intent: this person searched for THIS
   * specialty.
   */
  #[ORM\Column(name: 'matched_term_id', type: 'integer', nullable: true)]
  private ?int $matchedTermId = null;

  /**
   * The matched term's name and vocabulary, snapshotted.
   *
   * No foreign key, deliberately. A FK would either cascade — destroying
   * history when a term is tidied away — or SET NULL, losing which term it
   * was. Analytics must survive the taxonomy being reorganised, so the name is
   * copied at the time, exactly as the audit log copies its actor label.
   */
  #[ORM\Column(name: 'matched_term_name', type: 'string', length: 255, nullable: true)]
  private ?string $matchedTermName = null;

  #[ORM\Column(name: 'matched_vocabulary', type: 'string', length: 64, nullable: true)]
  private ?string $matchedVocabulary = null;

  /**
   * How many providers came back.
   *
   * Zero is the single most actionable number here: a search returning nothing
   * is a question the directory failed to answer, and the list of those terms
   * is a to-do list for the taxonomy.
   */
  #[ORM\Column(name: 'result_count', type: 'integer')]
  private int $resultCount = 0;

  /**
   * Which filters were applied, as a comma-separated list of keys.
   *
   * A plain string rather than JSON: it is only ever read for "how often is
   * the specialty filter used?", which a LIKE answers, and JSON would invite
   * storing structure nobody aggregates.
   */
  #[ORM\Column(name: 'filters_used', type: 'string', length: 255, nullable: true)]
  private ?string $filtersUsed = null;

  /**
   * Random per SEARCH, so a later click can be tied back to the search that
   * produced it — which is the only way to measure whether a search succeeded.
   *
   * Per-search rather than per-session on purpose: it links a search to its
   * consequences without linking one person's searches to each other.
   */
  #[ORM\Column(name: 'correlation_id', type: 'string', length: 32)]
  private string $correlationId;

  public function getId(): ?int { return $this->id; }
  public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
  public function getTerm(): ?string { return $this->term; }
  public function getMatchedTermId(): ?int { return $this->matchedTermId; }
  public function getMatchedTermName(): ?string { return $this->matchedTermName; }
  public function getMatchedVocabulary(): ?string { return $this->matchedVocabulary; }
  public function getResultCount(): int { return $this->resultCount; }
  public function getFiltersUsed(): ?string { return $this->filtersUsed; }
  public function getCorrelationId(): string { return $this->correlationId; }
}
