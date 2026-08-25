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
    return [
      self::Specialty,
      self::ClinicalInterest,
      self::Condition,
      self::Procedure,
      self::BoardCertification,
      self::Language,
    ];
  }

  /**
   * Whether a free-text query is matched against this vocabulary's terms.
   *
   * The specification defines free-text search as covering "name, condition,
   * procedure" — and specialty and clinical interest are in the ranking tiers,
   * so those are searchable too.
   *
   * Language and board certification are deliberately NOT. They are filters:
   * someone typing "Spanish" wants a Spanish-speaking provider, not every
   * physician who happens to list it, and folding them into keyword matching
   * would flood a name search with hundreds of weak hits. A filter answers
   * "narrow to this"; free text answers "find me something like this".
   */
  public function isFreeTextSearchable(): bool {
    return match ($this) {
      self::Specialty, self::ClinicalInterest, self::Condition, self::Procedure => true,
      self::Language, self::BoardCertification => false,
    };
  }

  /**
   * The wording for the "no filter applied" option on a select.
   *
   * "All specialties" reads naturally; "All languages" does not — someone
   * filtering by language wants ANY provider who speaks it, not all of them.
   * Small thing, but the alternative is a generic string that reads awkwardly
   * on half the controls.
   */
  public function anyOptionLabel(): string {
    return match ($this) {
      self::Specialty          => 'All specialties',
      self::ClinicalInterest   => 'Any clinical interest',
      self::Condition          => 'Any condition',
      self::Procedure          => 'Any procedure',
      self::BoardCertification => 'Any board certification',
      self::Language           => 'Any language',
    };
  }

  /**
   * The request parameter and filter key for this vocabulary.
   *
   * Identical to the enum's value by design — one name for one concept, so a
   * URL reads `?clinical_interest=12` and the repository's filter key is
   * `clinical_interest` with nothing in between to keep in step.
   */
  public function filterKey(): string {
    return $this->value;
  }

  /**
   * The vocabularies free-text search should match against.
   *
   * @return list<self>
   */
  public static function searchable(): array {
    return array_values(array_filter(self::active(), static fn (self $v): bool => $v->isFreeTextSearchable()));
  }
}
