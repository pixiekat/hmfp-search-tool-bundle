<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Services;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum\EditableField;
use Pixiekat\HMFPSearchToolBundle\Enum\EditReviewStatus;
use Pixiekat\HMFPSearchToolBundle\Repository\PhysicianEditRepository;
use Pixiekat\HMFPSearchToolBundle\Services\PhysicianTaxonomyManager;
use Pixiekat\SymfonyHelpers\Services\AuditLogManager;

/**
 * Proposes, reviews and resolves physician edits.
 *
 * ── The one rule ───────────────────────────────────────────────────────────
 *     resolved(field) = latest LIVE edit ?? imported value
 *
 * Everything else here serves that. The imported record is never written to,
 * so the importer keeps its file-authoritative behaviour with no exceptions
 * carved out for editable fields — the two systems write to different tables
 * and cannot fight.
 *
 * ── Scalars resolve; taxonomies project ────────────────────────────────────
 * A scalar override (bio) is resolved at READ time, which costs one batched
 * query per page and is always exactly consistent with the edit log.
 *
 * A taxonomy override (clinical interests) cannot work that way. It is a
 * REQUIRED search filter, and a required filter over a to-many has to be a
 * JOIN — you cannot answer "which physicians have this interest?" by parsing a
 * text column on every row. So an approved taxonomy edit is PROJECTED into
 * physician_terms, and search reads the projection.
 *
 * The cost of projecting is that the read model is derived state and can drift
 * if a projection fails part-way. The mitigation is the same one the importer
 * uses: make it idempotent and rebuildable from the edit log, so re-running is
 * always safe and always converges.
 */
final class PhysicianEditManager {

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly PhysicianEditRepository $edits,
    private readonly PhysicianTaxonomyManager $taxonomy,
    private readonly AuditLogManager $auditLogManager,
  ) {  }

  /**
   * Records a proposed set of taxonomy values — clinical interests today.
   *
   * The names are stored as JSON exactly as given (bar trimming and de-duping);
   * nothing is resolved to a Term here. Resolution happens at approval, because
   * that is when a human has agreed the terms should exist. Proposing must not
   * be able to create vocabulary.
   *
   * @param list<string> $names
   */
  public function proposeTerms(
    Entity\Physician $physician,
    EditableField $field,
    array $names,
    string $editedBy,
  ): Entity\PhysicianEdit {
    if (!$field->isTaxonomy()) {
      throw new \InvalidArgumentException(sprintf(
        '%s is not a taxonomy-backed field; use propose() instead.',
        $field->value,
      ));
    }

    // Trim, drop empties, and de-duplicate case-insensitively while keeping the
    // author's own capitalisation and ordering. Storing "Heart Failure" twice
    // because it was typed two ways is noise a reviewer should never have to
    // look at.
    $seen  = [];
    $clean = [];
    foreach ($names as $name) {
      $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));

      if ($name === '') {
        continue;
      }

      $key = mb_strtolower($name);
      if (isset($seen[$key])) {
        continue;
      }

      $seen[$key] = true;
      $clean[]    = $name;
    }

    // JSON_UNESCAPED_UNICODE so a reviewer reading the raw column sees
    // "Malformación" rather than a run of \u escapes.
    return $this->propose(
      $physician,
      $field,
      json_encode($clean, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
      $editedBy,
    );
  }

  /**
   * Records a proposed change. Does not flush.
   *
   * Always creates a NEW row, even where a pending edit for the same field
   * already exists — the table is append-only, and two competing proposals are
   * information a reviewer wants rather than a conflict to resolve silently.
   *
   * The edit is Pending, so it changes nothing anyone can see until approved.
   */
  public function propose(
    Entity\Physician $physician,
    EditableField $field,
    ?string $newValue,
    string $editedBy,
  ): Entity\PhysicianEdit {
    $edit = new Entity\PhysicianEdit($physician, $field, $newValue, $editedBy);

    $this->entityManager->persist($edit);

    return $edit;
  }

  /**
   * Approves an edit, superseding whatever it replaces.
   *
   * Order matters: older Live edits are marked Superseded BEFORE this one goes
   * Live, so there is no instant at which two edits for the same field are both
   * Live. (The resolver tolerates that state anyway — it takes the most recent —
   * but a workflow that produces impossible states even briefly is one whose
   * history cannot be trusted.)
   *
   * Does not flush; the caller decides the transaction boundary.
   */
  public function approve(Entity\PhysicianEdit $edit, Entity\User $reviewer): void {
    foreach ($this->edits->findLiveFor($edit->getPhysician(), $edit->getFieldName()) as $previous) {
      if ($previous !== $edit) {
        $previous->review(EditReviewStatus::Superseded, null);
      }
    }

    $edit->review(EditReviewStatus::Live, $reviewer);

    // Re-project immediately, in the SAME unit of work as the approval. If the
    // two were separate transactions there would be a window in which an edit
    // is live but the search index does not reflect it — and a projection that
    // can be forgotten is a projection that will be.
    if ($edit->getFieldName()->isTaxonomy()) {
      // Projected from THIS edit's value, not by re-reading the log.
      //
      // A DQL query does not see unflushed changes: the status set on the line
      // above exists only in memory, so a query at this point would still find
      // the edit Pending and project an EMPTY set — leaving the read model one
      // approval behind for ever. Passing the value we already hold sidesteps
      // the question entirely and avoids a needless query.
      $this->applyProjection($edit->getPhysician(), $edit->getFieldName(), $edit->getNewValue());
    }

    // Approving an edit is exactly the sort of thing the DATABASE audit sink is
    // for — low volume, and precisely what someone will need to look up later
    // ("who let this bio through?"). Contrast with search, which goes to the log
    // channel because it is high volume and individually uninteresting.
    //
    // flush: false so this row joins the caller's transaction rather than
    // committing a half-finished unit of work.
    $this->auditLogManager->log('physician_edit.approved', $edit, [
      'physicianId' => $edit->getPhysician()->getId(),
      'field'       => $edit->getFieldName()->value,
      'editedBy'    => $edit->getEditedBy(),
    ], flush: false);
  }

  /**
   * Refuses an edit. The row stays — see EditReviewStatus::Rejected.
   */
  public function reject(Entity\PhysicianEdit $edit, Entity\User $reviewer): void {
    $edit->review(EditReviewStatus::Rejected, $reviewer);

    $this->auditLogManager->log('physician_edit.rejected', $edit, [
      'physicianId' => $edit->getPhysician()->getId(),
      'field'       => $edit->getFieldName()->value,
      'editedBy'    => $edit->getEditedBy(),
    ], flush: false);
  }

  /**
   * Reverts to the imported value by superseding every live edit for a field.
   *
   * Nothing is deleted, so the history still shows what was overridden and for
   * how long — and re-approving the old edit puts it back.
   */
  public function revert(Entity\Physician $physician, EditableField $field, Entity\User $reviewer): void {
    foreach ($this->edits->findLiveFor($physician, $field) as $live) {
      $live->review(EditReviewStatus::Superseded, $reviewer);
    }

    // With no live edit left, the projection recomputes to an empty set and the
    // links are removed. Nothing is deleted from the log — re-approving the old
    // edit puts everything back.
    if ($field->isTaxonomy()) {
      // Every live edit was just superseded, so the desired set is provably
      // empty — no need to ask the database, which for the same
      // unflushed-changes reason would still say otherwise.
      $this->applyProjection($physician, $field, null);
    }

    $this->auditLogManager->log('physician_edit.reverted', $physician, [
      'field' => $field->value,
    ], flush: false);
  }

  /**
   * Rewrites a physician's taxonomy links to match their latest live edit.
   *
   * ── Why the read model is projected and not resolved ──────────────────────
   * Clinical interests are a REQUIRED search filter. A required filter over a
   * to-many has to be answerable by a JOIN — "which physicians have this
   * interest?" cannot be answered by decoding a JSON column on every one of
   * 10,933 rows. So the approved state is written into physician_terms, and
   * search reads that, exactly as it reads specialties and languages.
   *
   * ── Idempotent, and rebuildable from the edit log ─────────────────────────
   * This computes the FULL desired set and diffs it against what is stored, so
   * running it twice changes nothing and running it after a failure converges.
   * Nothing here depends on what the previous run did — the edit log is the
   * only input — which is what makes `hmfp:projections:rebuild` always safe.
   *
   * That matters because a projection is derived state and derived state
   * drifts: a half-finished request, a restored backup, a hand-edited row. The
   * answer to drift is not to prevent it but to make correcting it a single
   * idempotent command.
   *
   * ── No live edit means no interests ───────────────────────────────────────
   * An absent or superseded edit projects to an EMPTY set, which removes every
   * link. That is what makes revert() work: supersede the live edit, re-project,
   * and the interests disappear without anything being deleted from the log.
   *
   * Does not flush.
   *
   * @return array{added: int, removed: int}
   */
  public function project(Entity\Physician $physician, EditableField $field): array {
    if (!$field->isTaxonomy()) {
      throw new \InvalidArgumentException(sprintf('%s is not a taxonomy-backed field.', $field->value));
    }

    // Reads the live value from the database, so this entry point is only
    // correct when there are no unflushed status changes pending — which is
    // exactly the rebuild command's situation. Callers that have just changed a
    // status in memory must use applyProjection() with the value in hand
    // instead; see approve().
    return $this->applyProjection($physician, $field, $this->rawLiveValue($physician, $field));
  }

  /**
   * Rewrites the links for one field to match an explicitly supplied value.
   *
   * The value is a parameter rather than something this method looks up,
   * because the callers that know it best are the ones mid-transaction, where
   * a lookup would return stale data.
   *
   * @param string|null $rawValue JSON name list, or null for "no interests".
   *
   * @return array{added: int, removed: int}
   */
  private function applyProjection(
    Entity\Physician $physician,
    EditableField $field,
    ?string $rawValue,
  ): array {
    $vocabulary = $field->vocabulary();
    if ($vocabulary === null) {
      throw new \LogicException(sprintf('%s claims to be a taxonomy but names no vocabulary.', $field->value));
    }

    $desiredNames = $this->decodeNames($rawValue);

    $existingTerms = $this->taxonomy->termsByName($vocabulary);

    $desiredTerms = [];
    foreach ($desiredNames as $name) {
      $key = $this->normalise($name);

      // Find-or-create. Creating here rather than at proposal time is
      // deliberate: an approval is a human agreeing the term should exist.
      $term = $existingTerms[$key]
        ?? $this->taxonomy->createTerm($vocabulary, $name, source: 'physician-edit');

      $existingTerms[$key]      = $term;
      $desiredTerms[$key]       = $term;
    }

    // ── Current: this physician's links in THIS vocabulary only ────────────
    // Scoped, because physician_terms holds every taxonomy at once. Diffing
    // against the unscoped collection would delete their specialties and
    // languages the first time this ran.
    $currentTerms = [];
    foreach ($this->taxonomy->termsFor($physician, $vocabulary) as $term) {
      $currentTerms[$this->normalise((string) $term->getName())] = $term;
    }

    $added   = 0;
    $removed = 0;

    foreach ($desiredTerms as $key => $term) {
      if (!isset($currentTerms[$key])) {
        $physician->addTerm($term);
        $added++;
      }
    }

    foreach ($currentTerms as $key => $term) {
      if (!isset($desiredTerms[$key])) {
        $physician->removeTerm($term);
        $removed++;
      }
    }

    return ['added' => $added, 'removed' => $removed];
  }

  /**
   * The raw stored value of the latest live edit, or null if there is none.
   *
   * Distinct from resolveMany(), which falls back to the imported value — a
   * taxonomy field has no imported scalar to fall back to, and "no live edit"
   * must project to an empty set rather than to something inherited.
   */
  private function rawLiveValue(Entity\Physician $physician, EditableField $field): ?string {
    $id = $physician->getId();

    if ($id === null) {
      return null;
    }

    $overrides = $this->edits->findLiveOverrides([$id]);

    return $overrides[$id][$field->value] ?? null;
  }

  /**
   * Decodes the stored JSON name list, tolerantly.
   *
   * A projection must never be the thing that takes a page down. The column is
   * written by this class, so malformed content means something already went
   * wrong — a hand-edited row, a partial write, an older format. Treating that
   * as "no interests" keeps the physician's record readable and leaves the edit
   * visible in the log to be investigated, which is strictly better than a
   * fatal error on their profile.
   *
   * @return list<string>
   */
  private function decodeNames(?string $json): array {
    if ($json === null || trim($json) === '') {
      return [];
    }

    try {
      $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }

    if (!is_array($decoded)) {
      return [];
    }

    $names = [];
    foreach ($decoded as $name) {
      if (!is_string($name)) {
        continue;
      }

      $name = trim((string) preg_replace('/\s+/u', ' ', $name));

      if ($name !== '') {
        $names[] = $name;
      }
    }

    return $names;
  }

  /**
   * The same normalisation the taxonomy manager and importer use.
   */
  private function normalise(string $value): string {
    return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
  }

  /**
   * The value to DISPLAY for one physician and field.
   *
   * Convenience only. Rendering a list should use resolveMany() — this issues a
   * query per call, which is fine for a single profile page and an N+1 anywhere
   * else.
   */
  public function resolve(Entity\Physician $physician, EditableField $field): ?string {
    $resolved = $this->resolveMany([$physician], $field);

    return $resolved[$physician->getId()] ?? null;
  }

  /**
   * The values to DISPLAY for many physicians, in one query.
   *
   * The array_key_exists check is the important line. A live edit whose value
   * is NULL means "clear this field", which is a real edit and must beat the
   * imported value — whereas an absent key means no edit exists and the
   * imported value should show. `??` cannot tell those apart, which would make
   * clearing a field silently impossible.
   *
   * @param list<Entity\Physician> $physicians
   *
   * @return array<int, string|null> physician id → resolved value.
   */
  public function resolveMany(array $physicians, EditableField $field): array {
    $ids = array_values(array_filter(array_map(
      static fn (Entity\Physician $p): ?int => $p->getId(),
      $physicians,
    )));

    $overrides = $this->edits->findLiveOverrides($ids);

    $resolved = [];
    foreach ($physicians as $physician) {
      $id = $physician->getId();

      if ($id !== null && array_key_exists($field->value, $overrides[$id] ?? [])) {
        $resolved[$id] = $overrides[$id][$field->value];
        continue;
      }

      $resolved[$id] = $this->importedValue($physician, $field);
    }

    return $resolved;
  }

  /**
   * The value as imported — what shows when no live edit exists.
   *
   * An explicit match rather than a getter name derived from the enum: it is
   * greppable, the type checker sees it, and adding an editable field fails to
   * compile here rather than returning null at runtime.
   */
  private function importedValue(Entity\Physician $physician, EditableField $field): ?string {
    return match ($field) {
      EditableField::Bio => $physician->getBio(),

      // Taxonomy fields have no scalar imported value — they resolve through
      // physician_terms, which the projection maintains. Returning null here
      // rather than throwing keeps resolveMany() total, but a caller asking for
      // a taxonomy field this way has almost certainly made a mistake.
      EditableField::ClinicalInterests => null,
    };
  }
}
