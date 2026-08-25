<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Pixiekat\HMFPSearchToolBundle\Enum\EditableField;
use Pixiekat\HMFPSearchToolBundle\Enum\EditReviewStatus;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;

/**
 * One proposed change to one field of one physician.
 *
 * ── The override layer ─────────────────────────────────────────────────────
 * The imported record is never modified. Edits accumulate here instead, and a
 * read resolves as:
 *
 *     resolved(field) = newValue of the latest LIVE edit for that field
 *                       ?? the imported value on Physician
 *
 * Three properties fall out of that, all of which are the point:
 *
 *   · The importer needs no special cases. It stays fully file-authoritative
 *     over the columns it owns, because edits are not in those columns.
 *   · Reverting is deleting nothing — mark the live edit Superseded, or add a
 *     newer one, and the imported value shows through again.
 *   · The history is free. Every proposal ever made is still here, with who
 *     made it and who decided.
 *
 * ── Append-only means the VALUE is immutable ───────────────────────────────
 * There is deliberately no setter for $newValue. Changing your mind produces a
 * NEW edit; the old one becomes Superseded. Editing an edit in place would
 * destroy the audit trail this table exists to keep, and would make "what did
 * the reviewer actually approve?" unanswerable.
 *
 * Status is the one mutable part, because a review is by definition a later
 * decision about an existing proposal.
 *
 * ── Why editedBy is a string and reviewedBy is a relation ──────────────────
 * They are different populations. A reviewer is an administrator of THIS
 * application, so it can be a real foreign key. An editor is a physician, who
 * in the target architecture authenticates through the hospital's own identity
 * system and may have no row in this database at all. Storing an opaque
 * identifier keeps that door open; forcing a FK would require mirroring every
 * physician into the users table before they could touch their own profile.
 */
#[ORM\Entity(repositoryClass: \Pixiekat\HMFPSearchToolBundle\Repository\PhysicianEditRepository::class)]
#[ORM\Table(name: 'physician_edits')]
/*
 * The resolver's query is "latest LIVE edit for this physician and field", so
 * the index matches that access path exactly, in that column order. Without it
 * every profile view scans the table — which grows forever, since nothing here
 * is ever deleted.
 */
#[ORM\Index(name: 'IDX_PHYSEDIT_RESOLVE', columns: ['physician_id', 'field_name', 'review_status'])]
#[ORM\Index(name: 'IDX_PHYSEDIT_QUEUE', columns: ['review_status', 'edited_at'])]
class PhysicianEdit {
  use PixieTraits\EntityIdTrait;

  /**
   * The physician this edit is about.
   *
   * CASCADE on delete: an edit to a physician who no longer exists is not
   * history worth keeping, it is a dangling row that every query has to
   * exclude.
   */
  #[ORM\ManyToOne(targetEntity: Physician::class)]
  #[ORM\JoinColumn(name: 'physician_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
  private Physician $physician;

  /**
   * Which field is being changed.
   *
   * Stored as the enum's string value, and typed as the enum in PHP — so a row
   * naming a field that is not editable cannot be loaded into a valid object,
   * and cannot be created in the first place. The allow-list in EditableField
   * is therefore enforced by the mapping rather than by a validation call
   * somebody has to remember.
   */
  #[ORM\Column(name: 'field_name', type: 'string', length: 64, enumType: EditableField::class)]
  private EditableField $fieldName;

  /**
   * The proposed value.
   *
   * Nullable, because "clear this field" is a legitimate edit and must be
   * distinguishable from "no edit exists". Text rather than a narrower type
   * because it has to hold a bio.
   *
   * ── For taxonomy fields it holds a JSON array of NAMES, not ids ──────────
   * `["Heart failure", "Echocardiography"]`.
   *
   * Names rather than term ids, for three reasons:
   *
   *   1. A reviewer reads this column. `["Heart failure"]` can be judged at a
   *      glance; `[17, 42]` cannot, and a review workflow whose queue is
   *      unreadable will not be used properly.
   *   2. Ids are not stable against the taxonomy being tidied. A merged or
   *      re-created term silently changes what a historical edit meant, which
   *      is exactly the kind of quiet rewriting an audit table must not do.
   *   3. Physicians propose interests in their own words. Resolving a name to
   *      a term — creating it if new — happens at APPROVAL, which is the point
   *      at which a human has actually agreed the term should exist.
   *
   * The cost is that the vocabulary can grow through approvals. That is the
   * intended control: the reviewer is the gate, not a fixed list.
   *
   * No setter — see the class docblock on append-only.
   */
  #[ORM\Column(name: 'new_value', type: 'text', nullable: true)]
  private ?string $newValue;

  /**
   * Opaque identifier of whoever proposed this — see the class docblock.
   */
  #[ORM\Column(name: 'edited_by', type: 'string', length: 255)]
  private string $editedBy;

  #[ORM\Column(name: 'edited_at', type: 'datetime_immutable')]
  private \DateTimeImmutable $editedAt;

  #[ORM\Column(name: 'review_status', type: 'string', length: 32, enumType: EditReviewStatus::class)]
  private EditReviewStatus $reviewStatus = EditReviewStatus::Pending;

  /**
   * The administrator who decided. Null while pending.
   *
   * SET NULL rather than CASCADE: if that admin's account is later removed, the
   * decision still happened and the edit must survive it. Losing the reviewer's
   * name is regrettable; losing the record that the edit was approved at all
   * would be a hole in the audit trail.
   */
  #[ORM\ManyToOne(targetEntity: User::class)]
  #[ORM\JoinColumn(name: 'reviewed_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
  private ?User $reviewedBy = null;

  /**
   * When the decision was made.
   *
   * Not in the original design sketch, but a review without a timestamp cannot
   * answer "how long is the queue taking?" — which is the first question anyone
   * asks of a review workflow.
   */
  #[ORM\Column(name: 'reviewed_at', type: 'datetime_immutable', nullable: true)]
  private ?\DateTimeImmutable $reviewedAt = null;

  public function __construct(
    Physician $physician,
    EditableField $fieldName,
    ?string $newValue,
    string $editedBy,
    ?\DateTimeImmutable $editedAt = null,
  ) {
    $this->physician  = $physician;
    $this->fieldName  = $fieldName;
    $this->newValue   = $newValue;
    $this->editedBy   = $editedBy;
    $this->editedAt   = $editedAt ?? new \DateTimeImmutable();
  }

  public function getPhysician(): Physician {
    return $this->physician;
  }

  public function getFieldName(): EditableField {
    return $this->fieldName;
  }

  public function getNewValue(): ?string {
    return $this->newValue;
  }

  public function getEditedBy(): string {
    return $this->editedBy;
  }

  public function getEditedAt(): \DateTimeImmutable {
    return $this->editedAt;
  }

  public function getReviewStatus(): EditReviewStatus {
    return $this->reviewStatus;
  }

  public function getReviewedBy(): ?User {
    return $this->reviewedBy;
  }

  public function getReviewedAt(): ?\DateTimeImmutable {
    return $this->reviewedAt;
  }

  /**
   * Records a review decision.
   *
   * Both the reviewer and the timestamp are set here rather than by separate
   * setters, so an edit cannot end up approved with no record of who approved
   * it — the two facts are one event and are written as one.
   *
   * Superseding is done by the manager rather than a reviewer, so it accepts a
   * null reviewer without recording one.
   */
  public function review(EditReviewStatus $status, ?User $reviewer, ?\DateTimeImmutable $at = null): self {
    $this->reviewStatus = $status;

    if ($reviewer !== null) {
      $this->reviewedBy = $reviewer;
      $this->reviewedAt = $at ?? new \DateTimeImmutable();
    }

    return $this;
  }
}
