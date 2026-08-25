<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes edit authorship a relation instead of a bare string.
 *
 * `edited_by` was originally a varchar, written on the assumption that
 * physicians would authenticate through an external system and have no row
 * here. They will be ordinary users instead — password today, Azure OAuth
 * later — so it becomes a foreign key, paired with a snapshot label exactly as
 * the audit log pairs actor with actor_label.
 *
 * The pairing is not redundant. The key answers "who is this, now?" and the
 * label answers "who was this, then?". A review history needs the second even
 * after an account is deleted, and it leaves room for an author with no local
 * account at all — which is what an edit pushed from upstream would be.
 *
 * ── On matching a user to a physician ───────────────────────────────────────
 * There is deliberately no physicians.user_id column. A link table between the
 * two would have to be populated and maintained for eleven thousand people who
 * mostly never sign in, and it is not needed: nothing restricts editing to
 * "your own record" — any signed-in user may edit any physician, with review
 * catching what needs catching. Where the question "is this user the physician
 * they are editing?" ever does arise, the email addresses will almost always
 * match, which is cheaper than a column that must be kept true.
 */
final class Version20260825150000_ConvertEditAuthorToUser extends AbstractMigration {

  public function getDescription(): string {
    return 'Converts physician_edits.edited_by from a string to a users relation, with a label snapshot.';
  }

  public function up(Schema $schema): void {
    if (!$schema->hasTable('physician_edits')) {
      $this->write("Table 'physician_edits' does not exist, skipping.");
      return;
    }

    if ($schema->getTable('physician_edits')->hasColumn('edited_by_label')) {
      $this->write("Column 'physician_edits.edited_by_label' already exists, skipping.");
      return;
    }

    $this->write("Converting 'physician_edits.edited_by' to a user relation...");

    // The old varchar becomes the snapshot label, so nothing is lost: whatever
    // identifier a historical edit recorded is still exactly what it recorded.
    // Then edited_by is rebuilt as an integer key.
    //
    // Existing rows end with a NULL edited_by and their original label, which is
    // the correct reading of them: the author is known by name but was never
    // linked to an account.
    $this->addSql("ALTER TABLE physician_edits ADD edited_by_label VARCHAR(255) NOT NULL DEFAULT ''");
    $this->addSql('UPDATE physician_edits SET edited_by_label = edited_by');
    $this->addSql('ALTER TABLE physician_edits ALTER COLUMN edited_by_label DROP DEFAULT');

    $this->addSql('ALTER TABLE physician_edits DROP edited_by');
    $this->addSql('ALTER TABLE physician_edits ADD edited_by INT DEFAULT NULL');
    $this->addSql('CREATE INDEX IDX_PHYSEDIT_EDITOR ON physician_edits (edited_by)');
    $this->addSql(<<<'SQL'
      ALTER TABLE physician_edits
        ADD CONSTRAINT FK_PHYSEDIT_EDITOR
        FOREIGN KEY (edited_by) REFERENCES users (id) ON DELETE SET NULL;
    SQL);

    $this->write('Converted successfully.');
  }

  public function down(Schema $schema): void {
    if (!$schema->hasTable('physician_edits') || !$schema->getTable('physician_edits')->hasColumn('edited_by_label')) {
      return;
    }

    // Collapsing back to a single varchar keeps the LABEL, since that is the
    // human-readable half. The link to the account is lost and cannot be
    // rebuilt from a display name.
    $this->addSql('ALTER TABLE physician_edits DROP FOREIGN KEY FK_PHYSEDIT_EDITOR');
    $this->addSql('DROP INDEX IDX_PHYSEDIT_EDITOR ON physician_edits');
    $this->addSql('ALTER TABLE physician_edits DROP edited_by');
    $this->addSql('ALTER TABLE physician_edits CHANGE edited_by_label edited_by VARCHAR(255) NOT NULL');
  }
}
