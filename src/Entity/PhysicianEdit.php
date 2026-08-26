<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Pixiekat\HMFPSearchToolBundle\Enum\EditableField;
use Pixiekat\HMFPSearchToolBundle\Enum\EditReviewStatus;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;

/**
 * One proposed change to one field of one physician.
 */
#[ORM\Entity(repositoryClass: \Pixiekat\HMFPSearchToolBundle\Repository\PhysicianEditRepository::class)]
#[ORM\Table(name: 'physician_edits')]
/**
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
   * For taxonomy fields it holds a JSON array of NAMES, not ids
   * `["Heart failure", "Echocardiography"]`.
   */
  #[ORM\Column(name: 'new_value', type: 'text', nullable: true)]
  private ?string $newValue;

  /**
   * The account that proposed this edit.
   *
   * SET NULL on delete, for the same reason as $reviewedBy: if the account is
   * later removed the proposal still happened, and the history must survive it.
   * $editedByLabel keeps the record readable when that occurs.
   */
  #[ORM\ManyToOne(targetEntity: User::class)]
  #[ORM\JoinColumn(name: 'edited_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
  private ?User $editedBy = null;

  /**
   * Who proposed it, as they were named at the time. Never null.
   *
   * The same actor/actorLabel pairing the audit log uses, and for the same
   * reason: a foreign key answers "who is this, now?" while a snapshot answers
   * "who was this, then?" — and a review history needs the second question
   * answered even after an account is deleted or an address changes.
   *
   * Also covers the non-user case that will exist eventually: an edit arriving
   * from an upstream push has an author but no login here.
   */
  #[ORM\Column(name: 'edited_by_label', type: 'string', length: 255)]
  private string $editedByLabel;

  #[ORM\Column(name: 'edited_at', type: 'datetime_immutable')]
  private \DateTimeImmutable $editedAt;

  #[ORM\Column(name: 'review_status', type: 'string', length: 32, enumType: EditReviewStatus::class)]
  private EditReviewStatus $reviewStatus = EditReviewStatus::Unreviewed;

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

  /**
   * @param User|null $editedBy      The proposing account, if there is one.
   * @param string|null $editedByLabel Overrides the label; defaults to the
   *   user's identifier. Required when $editedBy is null.
   */
  public function __construct(
    Physician $physician,
    EditableField $fieldName,
    ?string $newValue,
    ?User $editedBy,
    ?string $editedByLabel = null,
    ?\DateTimeImmutable $editedAt = null,
  ) {
    $label = $editedByLabel ?? $editedBy?->getUserIdentifier();

    if ($label === null || trim($label) === '') {
      // Refused rather than defaulted, because an anonymous entry in a review
      // history is worse than no entry: it looks like a record while answering
      // none of the questions the record exists for.
      throw new \InvalidArgumentException(
        'A physician edit must name its author: pass a User, or an explicit label.'
      );
    }

    $this->physician      = $physician;
    $this->fieldName      = $fieldName;
    $this->newValue       = $newValue;
    $this->editedBy       = $editedBy;
    $this->editedByLabel  = $label;
    $this->editedAt       = $editedAt ?? new \DateTimeImmutable();
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

  public function getEditedBy(): ?User {
    return $this->editedBy;
  }

  public function getEditedByLabel(): string {
    return $this->editedByLabel;
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
