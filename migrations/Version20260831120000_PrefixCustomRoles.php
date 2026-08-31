<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives the application's custom roles the ROLE_ prefix Symfony requires.
 */
final class Version20260831120000_PrefixCustomRoles extends AbstractMigration {

  /**
   * The rename, old name => new name.
   *
   * ROLE_SYSADMIN, ROLE_ADMIN and ROLE_USER are absent deliberately: they were
   * always correctly prefixed and need no rewriting.
   */
  private const RENAMES = [
    'CONTENT_ADMIN' => 'ROLE_CONTENT_ADMIN',
    'DATA_STEWARD' => 'ROLE_DATA_STEWARD',
    'DEPARTMENT_EDITOR' => 'ROLE_DEPARTMENT_EDITOR',
    'ANALYTICS_VIEWER' => 'ROLE_ANALYTICS_VIEWER',
  ];

  public function getDescription(): string {
    return 'Prefixes the custom roles in users.roles with ROLE_, so Symfony\'s RoleVoter can see them.';
  }

  /**
   * Why this migration exists at all.
   *
   * Symfony's RoleVoter — the thing that answers isGranted() for plain roles,
   * and the parent of RoleHierarchyVoter — ignores any attribute that does not
   * start with its configured prefix:
   *
   *   if (!is_string($attribute) || !str_starts_with($attribute, $this->prefix))
   *       continue;   // $prefix = 'ROLE_'
   *
   * So while role_hierarchy expanded DATA_STEWARD perfectly happily, and the
   * profiler listed it among a user's reachable roles, isGranted('DATA_STEWARD')
   * was unanswerable: the voter abstained, nothing else granted it, and the
   * result was an unexplainable 403 for every data steward and analytics viewer.
   *
   * The names in code moved in HMFPSearchToolUserInterface; this moves the rows
   * that already exist to match. Both halves are required — a rename in only one
   * of them locks the affected users out just as thoroughly.
   */
  public function up(Schema $schema): void {
    $this->renameRoles(self::RENAMES);
  }

  public function down(Schema $schema): void {
    $this->renameRoles(array_flip(self::RENAMES));
  }

  /**
   * Rewrites role names inside the users.roles JSON document.
   *
   * roles is a JSON array of strings (LONGTEXT with a JSON check constraint on
   * MariaDB), so a plain string REPLACE over the serialised document is the
   * simplest correct approach — no JSON_TABLE, no round-trip through PHP.
   *
   * The quotes in the search term are what make it safe. Matching on
   * '"DATA_STEWARD"' rather than 'DATA_STEWARD' means the replacement can only
   * ever hit a complete JSON string, so a role that merely *contains* another
   * role's name as a substring cannot be corrupted, and re-running after a
   * partial rename cannot double-prefix an already-migrated row.
   *
   * @param array<string, string> $renames Old name => new name.
   */
  private function renameRoles(array $renames): void {
    if (!$this->connection->createSchemaManager()->tablesExist(['users'])) {
      $this->write("Table 'users' does not exist, skipping.");
      return;
    }

    foreach ($renames as $old => $new) {
      $this->write(sprintf("Renaming role %s to %s...", $old, $new));
      $this->addSql(
        'UPDATE users SET roles = REPLACE(roles, :old, :new) WHERE roles LIKE :like',
        [
          'old' => sprintf('"%s"', $old),
          'new' => sprintf('"%s"', $new),
          'like' => sprintf('%%"%s"%%', $old),
        ]
      );
    }
  }
}
