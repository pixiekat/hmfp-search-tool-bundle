<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the physician ↔ taxonomy-term join table.
 *
 * ── One table for every taxonomy ────────────────────────────────────────────
 * Specialty, Language, Condition, Procedure, BoardCertification and
 * ClinicalInterest are not six entities with six join tables. They are all
 * Terms in the helpers bundle's shared taxonomy, told apart by which Vocabulary
 * they belong to, and they all attach to a physician through this one table.
 *
 * ── This table is owned by HMFP, not by the helpers bundle ─────────────────
 * The association is unidirectional: Physician declares it, Term knows nothing
 * about it. That is deliberate. The helpers bundle is shared with other
 * projects and must not learn that physicians exist — so the relationship, and
 * therefore this table, lives here. Nothing in this migration touches a table
 * the helpers bundle owns.
 *
 * @see \Pixiekat\HMFPSearchToolBundle\Entity\Physician::$terms
 * @see \Pixiekat\HMFPSearchToolBundle\Enum\PhysicianVocabulary
 */
final class Version20260825130000_AddPhysicianTerms extends AbstractMigration {

  public function getDescription(): string {
    return 'Adds the physician_terms join table linking physicians to shared taxonomy terms.';
  }

  public function up(Schema $schema): void {
    if ($schema->hasTable('physician_terms')) {
      $this->write("Table 'physician_terms' already exists, skipping creation.");
      return;
    }

    if (!$schema->hasTable('vocabulary_terms')) {
      // The helpers bundle's migrations create this. Failing loudly here beats
      // creating a foreign key against a table that does not exist yet.
      throw new \RuntimeException(
        "Table 'vocabulary_terms' does not exist. Run the symfony-common-helpers "
        . 'migrations before this one.'
      );
    }

    $this->write("Creating table 'physician_terms'...");

    // Same shape as physician_departments and physician_facilities, and every
    // clause is there for the same reasons documented on those:
    //
    //   * composite PRIMARY KEY, so the same term cannot be attached twice;
    //   * ON DELETE CASCADE on both sides, so removing a physician or a term
    //     takes its link rows rather than being refused — note this removes
    //     the LINK only, never the term itself;
    //   * a standalone index on term_id, because the composite key only serves
    //     lookups starting with physician_id, and "which physicians have this
    //     specialty?" is exactly what the search filter asks.
    $this->addSql(<<<'SQL'
      CREATE TABLE physician_terms (
        physician_id INT NOT NULL,
        term_id INT NOT NULL,
        INDEX IDX_PHYSTERM_PHYSICIAN (physician_id),
        INDEX IDX_PHYSTERM_TERM (term_id),
        PRIMARY KEY (physician_id, term_id)
      ) DEFAULT CHARACTER SET utf8mb4;
    SQL);

    $this->addSql(<<<'SQL'
      ALTER TABLE physician_terms
        ADD CONSTRAINT FK_PHYSTERM_PHYSICIAN
        FOREIGN KEY (physician_id) REFERENCES physicians (id) ON DELETE CASCADE;
    SQL);

    $this->addSql(<<<'SQL'
      ALTER TABLE physician_terms
        ADD CONSTRAINT FK_PHYSTERM_TERM
        FOREIGN KEY (term_id) REFERENCES vocabulary_terms (id) ON DELETE CASCADE;
    SQL);

    $this->write("Table 'physician_terms' created successfully.");
  }

  public function down(Schema $schema): void {
    if (!$schema->hasTable('physician_terms')) {
      $this->write("Table 'physician_terms' does not exist, skipping.");
      return;
    }

    // Dropping this loses which terms were attached to whom. The terms and the
    // physicians both survive; only the links go. A re-import rebuilds them.
    $this->write("Dropping table 'physician_terms'...");
    $this->addSql('DROP TABLE physician_terms');
  }
}
