<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Promotes facility from a string on physicians to an entity with a join table.
 *
 * @see \Pixiekat\HMFPSearchToolBundle\Entity\Facility
 * @see \Pixiekat\HMFPSearchToolBundle\Entity\Physician::$facilities
 */
final class Version20260821200000_AddFacilities extends AbstractMigration {

  public function getDescription(): string {
    return 'Adds facilities + physician_facilities, and drops the physicians.facility_name scalar.';
  }

  public function up(Schema $schema): void {
    if ($schema->hasTable('facilities')) {
      $this->write("Table 'facilities' already exists, skipping creation.");
    }
    else {
      $this->write("Creating table 'facilities'...");
      $this->addSql(<<<'SQL'
        CREATE TABLE facilities (
          id INT AUTO_INCREMENT NOT NULL,
          name VARCHAR(255) NOT NULL,
          updated_at DATETIME DEFAULT NULL,
          created_at DATETIME NOT NULL,
          UNIQUE INDEX UNIQ_FACILITIES_NAME (name),
          PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4;
      SQL);
    }

    if ($schema->hasTable('physician_facilities')) {
      $this->write("Table 'physician_facilities' already exists, skipping creation.");
    }
    else {
      $this->write("Creating table 'physician_facilities'...");
      $this->addSql(<<<'SQL'
        CREATE TABLE physician_facilities (
          physician_id INT NOT NULL,
          facility_id INT NOT NULL,
          INDEX IDX_PHYSFAC_PHYSICIAN (physician_id),
          INDEX IDX_PHYSFAC_FACILITY (facility_id),
          PRIMARY KEY (physician_id, facility_id)
        ) DEFAULT CHARACTER SET utf8mb4;
      SQL);

      $this->addSql(<<<'SQL'
        ALTER TABLE physician_facilities
          ADD CONSTRAINT FK_PHYSFAC_PHYSICIAN
          FOREIGN KEY (physician_id) REFERENCES physicians (id) ON DELETE CASCADE;
      SQL);

      $this->addSql(<<<'SQL'
        ALTER TABLE physician_facilities
          ADD CONSTRAINT FK_PHYSFAC_FACILITY
          FOREIGN KEY (facility_id) REFERENCES facilities (id) ON DELETE CASCADE;
      SQL);

      $this->write("Table 'physician_facilities' created successfully.");
    }

    // drop the superseded scalar
    if (!$schema->hasTable('physicians')) {
      return;
    }

    if (!$schema->getTable('physicians')->hasColumn('facility_name')) {
      $this->write("Column 'physicians.facility_name' does not exist, nothing to drop.");
    }
    else {
      $this->write("Dropping superseded column 'physicians.facility_name'...");
      $this->addSql('ALTER TABLE physicians DROP facility_name');
    }
  }

  public function down(Schema $schema): void {
    if ($schema->hasTable('physician_facilities')) {
      $this->write("Dropping table 'physician_facilities'...");
      $this->addSql('DROP TABLE physician_facilities');
    }

    if ($schema->hasTable('facilities')) {
      $this->write("Dropping table 'facilities'...");
      $this->addSql('DROP TABLE facilities');
    }

    // Restored empty. Reconstructing "the one facility this physician was
    // arbitrarily assigned" is not possible once the full set has been
    // discarded, and inventing a winner would be worse than leaving it NULL —
    // a re-import repopulates the proper relationship anyway.
    if ($schema->hasTable('physicians') && !$schema->getTable('physicians')->hasColumn('facility_name')) {
      $this->write("Restoring 'physicians.facility_name' (empty)...");
      $this->addSql('ALTER TABLE physicians ADD facility_name VARCHAR(255) DEFAULT NULL');
    }
  }
}
