<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the physician ↔ department many-to-many, and relaxes departments.md_staff_code.
 */
final class Version20260821180000_AddPhysicianDepartmentRelation extends AbstractMigration {

  public function getDescription(): string {
    return 'Adds the physician_departments join table and makes departments.md_staff_code nullable.';
  }

  public function up(Schema $schema): void {
    if (!$schema->hasTable('departments')) {
      $this->write("Table 'departments' does not exist, skipping md_staff_code change.");
    }
    elseif ($schema->getTable('departments')->getColumn('md_staff_code')->getNotnull() === false) {
      $this->write("Column 'departments.md_staff_code' is already nullable, skipping.");
    }
    else {
      $this->write("Making 'departments.md_staff_code' nullable...");
      $this->addSql(<<<'SQL'
        ALTER TABLE departments
          MODIFY md_staff_code VARCHAR(255) DEFAULT NULL;
      SQL);
    }

    // ── physician_departments join table ────────────────────────────────────
    if ($schema->hasTable('physician_departments')) {
      $this->write("Table 'physician_departments' already exists, skipping creation.");
    }
    else {
      $this->write("Creating table 'physician_departments'...");
      $this->addSql(<<<'SQL'
        CREATE TABLE physician_departments (
          physician_id INT NOT NULL,
          department_id INT NOT NULL,
          INDEX IDX_PHYSDEPT_PHYSICIAN (physician_id),
          INDEX IDX_PHYSDEPT_DEPARTMENT (department_id),
          PRIMARY KEY (physician_id, department_id)
        ) DEFAULT CHARACTER SET utf8mb4;
      SQL);

      $this->addSql(<<<'SQL'
        ALTER TABLE physician_departments
          ADD CONSTRAINT FK_PHYSDEPT_PHYSICIAN
          FOREIGN KEY (physician_id) REFERENCES physicians (id) ON DELETE CASCADE;
      SQL);

      $this->addSql(<<<'SQL'
        ALTER TABLE physician_departments
          ADD CONSTRAINT FK_PHYSDEPT_DEPARTMENT
          FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE;
      SQL);

      $this->write("Table 'physician_departments' created successfully.");
    }
  }

  public function down(Schema $schema): void {
    if (!$schema->hasTable('physician_departments')) {
      $this->write("Table 'physician_departments' does not exist, skipping.");
    }
    else {
      $this->write("Dropping table 'physician_departments'...");
      $this->addSql('DROP TABLE physician_departments');
    }

    if (!$schema->hasTable('departments')) {
      $this->write("Table 'departments' does not exist, skipping md_staff_code revert.");
      return;
    }

    $this->write("Reverting 'departments.md_staff_code' to NOT NULL (NULLs become '')...");
    $this->addSql(<<<'SQL'
      UPDATE departments SET md_staff_code = '' WHERE md_staff_code IS NULL;
    SQL);
    $this->addSql(<<<'SQL'
      ALTER TABLE departments
        MODIFY md_staff_code VARCHAR(255) NOT NULL;
    SQL);
  }
}
