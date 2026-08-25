<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Enum;

/**
 * The vocabularies HMFP attaches to a physician.
 *
 * The helpers bundle's shared taxonomy is generic; this enum is HMFP's definition
 * of what it means. The vocabulary names are the machine names used in the database,
 * and the labels are the human-readable names used in forms and templates.
 */
enum PhysicianVocabulary: string {

  /**
   * Clinical specialty, e.g. "Internal Medicine", "Radiology-Diagnostic Radiology".
   *
   * Hierarchical in principle — Term::$parent supports sub-specialties — though
   * the demographics extract supplies a flat list, so imported terms are all
   * roots until something richer arrives.
   */
  case Specialty = 'specialty';

  /**
   * Languages a provider practises in, beyond English.
   */
  case Language = 'language';

  /**
   * Conditions treated. Term::$code is intended to carry the SNOMED code.
   */
  case Condition = 'condition';

  /**
   * Procedures performed.
   */
  case Procedure = 'procedure';

  /**
   * Board certifications held.
   */
  case BoardCertification = 'board_certification';

  /**
   * Narrower clinical interests, typically sitting beneath a Specialty via
   * Term::$parent.
   */
  case ClinicalInterest = 'clinical_interest';

  /**
   * The human-readable name for the Vocabulary row and for form labels.
   *
   * Kept beside the machine name rather than in a translation file for now, so
   * there is exactly one place to look. Move to translations when the app needs
   * a second language — the enum case, not this string, is what code depends on.
   */
  public function label(): string {
    return match ($this) {
      self::Specialty          => 'Specialty',
      self::Language           => 'Language',
      self::Condition          => 'Condition',
      self::Procedure          => 'Procedure',
      self::BoardCertification => 'Board certification',
      self::ClinicalInterest   => 'Clinical interest',
    };
  }

  /**
   * The label used for a filter control on the search form.
   *
   * Separate from label() because a form control reads better in the plural
   * ("All specialties") than the singular name of the vocabulary.
   */
  public function pluralLabel(): string {
    return match ($this) {
      self::Specialty          => 'Specialties',
      self::Language           => 'Languages',
      self::Condition          => 'Conditions',
      self::Procedure          => 'Procedures',
      self::BoardCertification => 'Board certifications',
      self::ClinicalInterest   => 'Clinical interests',
    };
  }

  /**
   * The vocabularies currently wired end to end.
   *
   * The cases above describe the intended model; this is what actually has data
   * and a filter today. Keeping the two apart means the enum can document the
   * full plan without the search page sprouting five empty dropdowns.
   *
   * @return list<self>
   */
  public static function active(): array {
    return [self::Specialty, self::Language, self::ClinicalInterest];
  }
}
