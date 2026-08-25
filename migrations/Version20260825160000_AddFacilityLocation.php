<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives facilities an address and coordinates.
 *
 * ── Where the data comes from ───────────────────────────────────────────────
 * Not the provider extract, which supplies a bare `facility_name` and nothing
 * else. These are entered by hand through the admin. That is proportionate
 * rather than lazy: there are TWENTY facilities, so filling them in is an
 * afternoon, and it avoids depending on a geocoding service to place a list
 * that changes perhaps twice a year.
 *
 * Every column is nullable, so nothing has to be filled in before the import
 * can keep running. A facility without coordinates is simply invisible to
 * distance search until someone places it.
 *
 * ── Why no spatial column or index ──────────────────────────────────────────
 * MariaDB 10.11 here does support ST_Distance_Sphere, and a POINT column with
 * a SPATIAL index is the textbook answer. It is the wrong answer at this size:
 * that machinery exists to avoid scanning thousands of rows, and there are
 * twenty. Computing the exact distance to every one of them in PHP costs
 * microseconds, needs no spatial types, and works on any database.
 *
 * Revisit if this table ever holds thousands of sites — the entity comment on
 * Facility::$latitude records the same trigger.
 */
final class Version20260825160000_AddFacilityLocation extends AbstractMigration {

  public function getDescription(): string {
    return 'Adds address, coordinates and epic_id to facilities.';
  }

  public function up(Schema $schema): void {
    if (!$schema->hasTable('facilities')) {
      $this->write("Table 'facilities' does not exist, skipping.");
      return;
    }

    $table = $schema->getTable('facilities');

    if ($table->hasColumn('latitude')) {
      $this->write("Facility location columns already exist, skipping.");
      return;
    }

    $this->write('Adding address and coordinate columns to facilities...');

    // postal_code is VARCHAR, never numeric: a ZIP is an identifier written
    // with digits, and storing it as a number drops the leading zero from every
    // code in New England — 02215 becomes 2215.
    $this->addSql(<<<'SQL'
      ALTER TABLE facilities
        ADD address_line VARCHAR(255) DEFAULT NULL,
        ADD city VARCHAR(128) DEFAULT NULL,
        ADD state VARCHAR(8) DEFAULT NULL,
        ADD postal_code VARCHAR(16) DEFAULT NULL,
        ADD latitude NUMERIC(10, 7) DEFAULT NULL,
        ADD longitude NUMERIC(10, 7) DEFAULT NULL,
        ADD epic_id VARCHAR(64) DEFAULT NULL;
    SQL);

    // Two facilities sharing an Epic id is a contradiction. NULLs are exempt
    // from a unique index in MySQL, so the twenty rows that have none coexist.
    $this->addSql('CREATE UNIQUE INDEX UNIQ_FACILITIES_EPIC_ID ON facilities (epic_id)');

    // Not a spatial index — see the class docblock. This plain index serves the
    // only query that filters on coordinates at all: "which facilities have
    // been placed?", which precedes every distance calculation.
    $this->addSql('CREATE INDEX IDX_FACILITIES_PLACED ON facilities (latitude, longitude)');

    $this->write('Added successfully.');
  }

  public function down(Schema $schema): void {
    if (!$schema->hasTable('facilities') || !$schema->getTable('facilities')->hasColumn('latitude')) {
      return;
    }

    // Genuinely lossy: these addresses were typed in by a person and exist
    // nowhere upstream, so nothing can rebuild them. Export before running this
    // anywhere the twenty rows have actually been filled in.
    $this->write('Dropping facility location columns — hand-entered addresses will be lost.');

    $this->addSql('DROP INDEX UNIQ_FACILITIES_EPIC_ID ON facilities');
    $this->addSql('DROP INDEX IDX_FACILITIES_PLACED ON facilities');
    $this->addSql(<<<'SQL'
      ALTER TABLE facilities
        DROP address_line, DROP city, DROP state, DROP postal_code,
        DROP latitude, DROP longitude, DROP epic_id;
    SQL);
  }
}
