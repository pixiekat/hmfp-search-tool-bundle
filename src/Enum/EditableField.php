<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Enum;

/**
 * The physician fields that may be changed through the override layer.
 *
 * This is deliberately an allow list rather than a deny list: the specification
 * names four fields as non-editable, and any new field added to the schema is
 * non-editable until someone deliberately adds it here.
 */
enum EditableField: string {

  /**
   * Free-text biography.
   *
   * The simplest possible case and the one wired end to end first: one field,
   * one string, one diff.
   */
  case Bio = 'bio';

  /**
   * Clinical interests.
   *
   * NOT a scalar — a set of taxonomy terms. An edit records the PROPOSAL here
   * (as a list of term ids), and on approval a projection writes the resulting
   * links into physician_terms, which is what search reads. See
   * PhysicianEditManager for why the read model is projected rather than
   * resolved at query time: "clinical interests" is a required search filter,
   * and a required filter over a to-many cannot be answered by parsing text
   * blobs per row.
   */
  case ClinicalInterests = 'clinical_interests';

  /**
   * Human-readable name, for form labels and the review queue.
   */
  public function label(): string {
    return match ($this) {
      self::Bio               => 'Biography',
      self::ClinicalInterests => 'Clinical interests',
    };
  }

  /**
   * Whether this field holds a single value or a set of taxonomy terms.
   *
   * The difference decides how an approved edit is applied: a scalar is read
   * straight back out of the edit, while a taxonomy edit has to be projected
   * into physician_terms before search can see it.
   */
  public function isTaxonomy(): bool {
    return match ($this) {
      self::Bio               => false,
      self::ClinicalInterests => true,
    };
  }

  /**
   * The vocabulary a taxonomy-backed field writes into, or null for scalars.
   */
  public function vocabulary(): ?PhysicianVocabulary {
    return match ($this) {
      self::Bio               => null,
      self::ClinicalInterests => PhysicianVocabulary::ClinicalInterest,
    };
  }

  /**
   * The fields wired end to end today.
   *
   * Same split as PhysicianVocabulary::active(): the cases above describe the
   * intended model, this is what actually works. Keeps the enum honest as
   * documentation without exposing half-built fields in a UI.
   *
   * @return list<self>
   */
  public static function active(): array {
    return [self::Bio, self::ClinicalInterests];
  }
}
