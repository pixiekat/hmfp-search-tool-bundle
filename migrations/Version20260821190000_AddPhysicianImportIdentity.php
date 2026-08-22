<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives physicians a stable import identity, so re-imports can UPDATE.
 *
 * Adds two columns, both nullable, neither of which disturbs existing rows:
 *
 *   cred_id                 the upstream credentialing UUID — the only stable
 *                           identifier the demographics extract provides
 *   last_seen_in_import_at  when the physician last appeared in an import,
 *                           which is how departures are detected without
 *                           deleting anything
 *
 * Before this, the importer could only insert: it matched existing physicians
 * on legal name plus credentials, so a changed degree produced a duplicate row
 * and a changed department list was ignored entirely. See the class docblock on
 * ImportPhysiciansCommand for the full picture.
 *
 * ── Backfill ────────────────────────────────────────────────────────────────
 * Deliberately none. There is no way to work out a physician's cred_id from
 * what is already stored — that is the entire problem this migration solves —
 * so existing rows keep NULL and the importer adopts them on its next run by
 * matching name and credentials ONCE, then writing their cred_id in. After that
 * first adopting run every row has a real identity and the fallback stops being
 * used. Nothing here has to guess.
 */
final class Version20260821190000_AddPhysicianImportIdentity extends AbstractMigration {

  public function getDescription(): string {
    return 'Adds physicians.cred_id (unique) and physicians.last_seen_in_import_at for idempotent re-imports.';
  }

  public function up(Schema $schema): void {
    if (!$schema->hasTable('physicians')) {
      $this->write("Table 'physicians' does not exist, skipping.");
      return;
    }

    $table = $schema->getTable('physicians');

    // Both columns are added NULLable with no default, which is why this is
    // safe on a populated table: existing rows need no rewrite of their data,
    // and nothing has to be invented to satisfy a NOT NULL constraint.
    if ($table->hasColumn('cred_id')) {
      $this->write("Column 'physicians.cred_id' already exists, skipping.");
    }
    else {
      $this->write("Adding 'physicians.cred_id'...");
      $this->addSql(<<<'SQL'
        ALTER TABLE physicians ADD cred_id VARCHAR(36) DEFAULT NULL;
      SQL);

      // UNIQUE, not a plain index. Two physicians sharing a credentialing UUID
      // is a contradiction, and letting the database enforce that means an
      // importer bug fails loudly at insert time instead of quietly producing
      // duplicate people.
      //
      // This is safe to add immediately even though every existing row is about
      // to hold NULL: MySQL does not treat NULLs as equal to one another under a
      // UNIQUE index, so any number of them coexist happily. That is also what
      // permits unlimited hand-created physicians, which legitimately have no
      // cred_id at all.
      $this->addSql(<<<'SQL'
        CREATE UNIQUE INDEX UNIQ_PHYSICIANS_CRED_ID ON physicians (cred_id);
      SQL);
    }

    if ($table->hasColumn('last_seen_in_import_at')) {
      $this->write("Column 'physicians.last_seen_in_import_at' already exists, skipping.");
    }
    else {
      $this->write("Adding 'physicians.last_seen_in_import_at'...");
      $this->addSql(<<<'SQL'
        ALTER TABLE physicians ADD last_seen_in_import_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)';
      SQL);

      // Indexed because the departure query — "who was in a previous extract but
      // not the latest?" — filters on this column across the whole table, and is
      // exactly the sort of thing that gets run from an admin screen.
      $this->addSql(<<<'SQL'
        CREATE INDEX IDX_PHYSICIANS_LAST_SEEN ON physicians (last_seen_in_import_at);
      SQL);
    }
  }

  public function down(Schema $schema): void {
    if (!$schema->hasTable('physicians')) {
      $this->write("Table 'physicians' does not exist, skipping.");
      return;
    }

    $table = $schema->getTable('physicians');

    // Dropping these is genuinely lossy: cred_id cannot be reconstructed from
    // anything else in the row, so rolling back and re-migrating forward leaves
    // every physician needing to be adopted by name again. Worth knowing before
    // running this against anything but a development database.
    if ($table->hasColumn('last_seen_in_import_at')) {
      $this->write("Dropping 'physicians.last_seen_in_import_at'...");
      $this->addSql('DROP INDEX IDX_PHYSICIANS_LAST_SEEN ON physicians');
      $this->addSql('ALTER TABLE physicians DROP last_seen_in_import_at');
    }

    if ($table->hasColumn('cred_id')) {
      $this->write("Dropping 'physicians.cred_id'...");
      $this->addSql('DROP INDEX UNIQ_PHYSICIANS_CRED_ID ON physicians');
      $this->addSql('ALTER TABLE physicians DROP cred_id');
    }
  }
}
