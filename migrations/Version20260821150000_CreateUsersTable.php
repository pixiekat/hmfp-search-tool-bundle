<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the users table.
 *
 * ── WHY THIS IS BACK-DATED ─────────────────────────────────────────────────
 * The users table existed before any migration in this bundle; it was
 * originally built outside the migration history (a schema:update). So on
 * every database that exists today, this migration has nothing to do and the
 * hasTable() guard below turns it into a no-op.
 *
 * It exists for FRESH installs, where nothing else creates the table. The
 * timestamp is deliberately the earliest in the bundle, because later
 * migrations depend on it:
 *   - Version20260821180000_AddAuthCodeFieldToUsers adds a column to it
 *   - Version20260825140000_AddPhysicianEdits and
 *     Version20260825150000_ConvertEditAuthorToUser add foreign keys to it
 *
 * It also helps the symfony-common-helpers migrations: they find the user
 * table with ResolvesUserTableTrait and only add their foreign keys
 * (audit_logs.actor_id, shouts.author_id) when it already exists. Doctrine
 * sorts migrations by fully-qualified class name, so
 * Pixiekat\HMFPSearchToolBundle\… runs before Pixiekat\SymfonyHelpers\….
 *
 * ── WHY auth_code IS NOT HERE ──────────────────────────────────────────────
 * This creates the table as it was ORIGINALLY, so the history stays honest:
 * AddAuthCodeFieldToUsers adds auth_code afterwards, just as it did on
 * existing databases. That way a fresh install and an old install end up with
 * the same columns from the same steps.
 *
 * ── WHY THE INDEX KEEPS DOCTRINE'S HASHED NAME ─────────────────────────────
 * The other migrations in this bundle use readable index names. This one
 * keeps UNIQ_1483A5E9B08E074E because that is what every existing database
 * already has. A readable name here would make fresh installs quietly differ
 * from existing ones, which is worse than an ugly name.
 *
 * @see \Pixiekat\HMFPSearchToolBundle\Entity\User
 */
final class Version20260821150000_CreateUsersTable extends AbstractMigration {

  public function getDescription(): string {
    return 'Creates the users table (no-op where it already exists).';
  }

  public function up(Schema $schema): void {
    if ($schema->hasTable('users')) {
      $this->write("Table 'users' already exists, skipping creation.");
      return;
    }

    $this->write("Creating table 'users'...");

    // Column notes:
    //   - roles is JSON. On MariaDB, JSON is an alias for LONGTEXT with a
    //     json_valid() CHECK constraint, which is what SHOW CREATE TABLE
    //     reports on existing databases. Same table, two spellings.
    //   - password is VARCHAR(255), not a fixed width: the 'auto' hasher can
    //     switch algorithms (bcrypt → argon2id) and hashes differ in length.
    //   - is_active defaults to 1, matching EntityActiveTrait, so rows inserted
    //     outside Doctrine (a SQL import) come in as active.
    $this->addSql(<<<'SQL'
      CREATE TABLE users (
        id INT AUTO_INCREMENT NOT NULL,
        email_address VARCHAR(255) NOT NULL,
        password VARCHAR(255) NOT NULL,
        roles JSON NOT NULL,
        is_active TINYINT DEFAULT 1 NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE INDEX UNIQ_1483A5E9B08E074E (email_address),
        PRIMARY KEY (id)
      ) DEFAULT CHARACTER SET utf8mb4;
    SQL);

    // addSql() only QUEUES the statement; it runs after up() returns. So this
    // means "queued", and a failure will still surface as a migration error.
    $this->write("Table 'users' queued for creation.");
  }

  public function down(Schema $schema): void {
    // Deliberately refuses rather than dropping. This table holds every
    // account, and other tables (physician_edits, audit_logs, shouts) point
    // foreign keys at it, so a DROP would either fail on those constraints or
    // wipe every login. If you really mean it, do it by hand.
    $this->throwIrreversibleMigrationException(
      "Refusing to drop 'users': it holds every account and other tables reference it."
    );
  }
}
