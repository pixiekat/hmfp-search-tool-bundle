<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821151832_CreatePhysiciansTable extends AbstractMigration {

  public function getDescription(): string {
    return 'Creates the physicians table which imports physicians into the system.';
  }

  public function up(Schema $schema): void {
    // check if the table already exists, if so, skip this migration
    if ($schema->hasTable('physicians')) {
      $this->write("Table 'physicians' already exists, skipping migration.");
    }
    else {
      $this->write("Creating table 'physicians'...");
      $this->addSql(<<<'SQL'
        CREATE TABLE physicians (
          id INT AUTO_INCREMENT NOT NULL,
          legal_name VARCHAR(255) NOT NULL,
          credentials VARCHAR(255) NOT NULL,
          facility_name VARCHAR(255) DEFAULT NULL,
          bio LONGTEXT DEFAULT NULL,
          updated_at DATETIME DEFAULT NULL,
          PRIMARY KEY (id)
        )
        DEFAULT CHARACTER SET utf8mb4;
      SQL);
      $this->write("Table 'physicians' created successfully.");

      // check if the departments table exists, if not, create it
      if ($schema->hasTable('departments')) {
        $this->write("Table 'departments' already exists, skipping creation.");
      }
      else {
        $this->write("Creating table 'departments'...");
        $this->addSql(<<<'SQL'
          CREATE TABLE departments (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            md_staff_code VARCHAR(255) NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_16AEB8D45E237E06 (name),
            PRIMARY KEY (id)
          ) DEFAULT CHARACTER SET utf8mb4;
        SQL);
        $this->write("Table 'departments' created successfully.");
      }
    }
  }

  public function down(Schema $schema): void {
    // check if the table exists, if not, skip this migration
    if (!$schema->hasTable('physicians')) {
      $this->write("Table 'physicians' does not exist, skipping migration.");
    }
    else {
      $this->write("Dropping table 'physicians'...");
      $this->addSql('DROP TABLE physicians');
      $this->write("Table 'physicians' dropped successfully.");
    }

    // check if the departments table exists, if not, skip this migration
    if (!$schema->hasTable('departments')) {
      $this->write("Table 'departments' does not exist, skipping migration.");
    }
    else {
      $this->write("Dropping table 'departments'...");
      $this->addSql('DROP TABLE departments');
      $this->write("Table 'departments' dropped successfully.");
    }
  }
}
