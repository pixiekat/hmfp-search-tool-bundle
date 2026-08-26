<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the physician ↔ taxonomy-term join table.
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

    $this->write("Dropping table 'physician_terms'...");
    $this->addSql('DROP TABLE physician_terms');
  }
}
