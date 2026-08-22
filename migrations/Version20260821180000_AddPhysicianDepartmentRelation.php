<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the physician ↔ department many-to-many, and relaxes departments.md_staff_code.
 *
 * Two changes travel together because they arrived together, from the same
 * source: the provider demographics extract lists one row per physician per
 * department (hence the join table) and carries no MD staff code at all (hence
 * the nullable column). Splitting them across two migrations would leave an
 * intermediate state where the importer can create the links but not the
 * departments to link to.
 *
 * Following the style of Version20260821151832, every statement is guarded so
 * the migration is safe to run against a database that has been partially
 * built by hand.
 */
final class Version20260821180000_AddPhysicianDepartmentRelation extends AbstractMigration {

  public function getDescription(): string {
    return 'Adds the physician_departments join table and makes departments.md_staff_code nullable.';
  }

  public function up(Schema $schema): void {
    // ── departments.md_staff_code → nullable ────────────────────────────────
    // Widening a constraint (NOT NULL → NULL) is always safe on existing rows:
    // every value already stored still satisfies the looser rule, so no data
    // is touched and no backfill is needed. It is the reverse direction, in
    // down(), that has to worry.
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

      // Notes on the shape of this table, since a Doctrine-generated join table
      // looks unremarkable but every clause is doing something:
      //
      //   * The PRIMARY KEY is the composite (physician_id, department_id).
      //     There is no surrogate id, and there should not be: the pair IS the
      //     identity of the row, and making it the key is what stops the same
      //     physician being linked to the same department twice.
      //
      //   * ON DELETE CASCADE on both foreign keys means deleting a physician
      //     or a department takes its link rows with it. Without it, MySQL
      //     would refuse the delete outright once any link existed. Note this
      //     cascades the LINK only — deleting a physician never deletes a
      //     department, which is the behaviour we want.
      //
      //   * The standalone index on department_id exists because the composite
      //     primary key is only usable for lookups that start with
      //     physician_id. "Which physicians are in this department?" — the
      //     query the search tool will lean on hardest — would otherwise be a
      //     full table scan.
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

    // ── Reverting md_staff_code to NOT NULL ─────────────────────────────────
    // This is the direction that can fail. Any department imported since up()
    // ran has a NULL code, and MySQL will reject the ALTER (or, worse, silently
    // coerce the NULLs to '' depending on strict mode). Setting the NULLs to an
    // empty string first makes the outcome explicit and identical either way.
    //
    // Worth being clear-eyed about: this loses the distinction between "no code
    // was ever supplied" and "the code is an empty string". A rollback is not
    // lossless here, which is the honest cost of the original column having
    // been NOT NULL without a source to fill it.
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
