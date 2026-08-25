<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the physician edit override layer.
 *
 * Proposed changes accumulate here instead of modifying the imported record, so
 * a read resolves as "latest live edit, else the imported value". The importer
 * never touches this table and this table never touches the importer's columns
 * — which is precisely what lets the import stay file-authoritative with no
 * special cases for editable fields.
 *
 * Nothing here is ever deleted: rejected and superseded edits are the history.
 *
 * @see \Pixiekat\HMFPSearchToolBundle\Entity\PhysicianEdit
 * @see \Pixiekat\HMFPSearchToolBundle\Services\PhysicianEditManager
 */
final class Version20260825140000_AddPhysicianEdits extends AbstractMigration {

  public function getDescription(): string {
    return 'Adds the physician_edits override/review table.';
  }

  public function up(Schema $schema): void {
    if ($schema->hasTable('physician_edits')) {
      $this->write("Table 'physician_edits' already exists, skipping creation.");
      return;
    }

    $this->write("Creating table 'physician_edits'...");

    // Notes on the shape:
    //
    //   * new_value is nullable because "clear this field" is a real edit and
    //     must be distinguishable from "no edit exists". The resolver checks
    //     for the KEY, not for a non-null value.
    //
    //   * edited_by is a plain varchar while reviewed_by is a foreign key,
    //     because they are different populations: reviewers are administrators
    //     of this application, editors are physicians who will authenticate
    //     through the hospital's identity system and may have no row here.
    //
    //   * reviewed_by is ON DELETE SET NULL, not CASCADE. If an administrator's
    //     account is removed the decision still happened; losing their name is
    //     acceptable, losing the record of the approval is not.
    $this->addSql(<<<'SQL'
      CREATE TABLE physician_edits (
        id INT AUTO_INCREMENT NOT NULL,
        physician_id INT NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        new_value LONGTEXT DEFAULT NULL,
        edited_by VARCHAR(255) NOT NULL,
        edited_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
        review_status VARCHAR(32) NOT NULL,
        reviewed_by INT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
        INDEX IDX_PHYSEDIT_PHYSICIAN (physician_id),
        INDEX IDX_PHYSEDIT_REVIEWER (reviewed_by),
        PRIMARY KEY (id)
      ) DEFAULT CHARACTER SET utf8mb4;
    SQL);

    // Matches the resolver's access path exactly, in its column order:
    // "live edits for this physician and field". Without it, every profile view
    // scans a table that only ever grows.
    $this->addSql(<<<'SQL'
      CREATE INDEX IDX_PHYSEDIT_RESOLVE
        ON physician_edits (physician_id, field_name, review_status);
    SQL);

    // The review queue's access path: pending, oldest first.
    $this->addSql(<<<'SQL'
      CREATE INDEX IDX_PHYSEDIT_QUEUE ON physician_edits (review_status, edited_at);
    SQL);

    $this->addSql(<<<'SQL'
      ALTER TABLE physician_edits
        ADD CONSTRAINT FK_PHYSEDIT_PHYSICIAN
        FOREIGN KEY (physician_id) REFERENCES physicians (id) ON DELETE CASCADE;
    SQL);

    $this->addSql(<<<'SQL'
      ALTER TABLE physician_edits
        ADD CONSTRAINT FK_PHYSEDIT_REVIEWER
        FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL;
    SQL);

    $this->write("Table 'physician_edits' created successfully.");
  }

  public function down(Schema $schema): void {
    if (!$schema->hasTable('physician_edits')) {
      return;
    }

    // Genuinely destructive: this table IS the edit history, and none of it can
    // be reconstructed from the imported record — that is the whole point of
    // keeping the two apart. Export before running this anywhere real.
    $this->write("Dropping table 'physician_edits' — the edit history is lost.");
    $this->addSql('DROP TABLE physician_edits');
  }
}
