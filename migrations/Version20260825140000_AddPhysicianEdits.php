<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the physician edit override layer.
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

    $this->write("Dropping table 'physician_edits' — the edit history is lost.");
    $this->addSql('DROP TABLE physician_edits');
  }
}
