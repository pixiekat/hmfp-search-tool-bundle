<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds physician delegations: people a claimant has allowed to propose edits
 * to their profile on their behalf.
 *
 * @see \Pixiekat\HMFPSearchToolBundle\Entity\PhysicianDelegation
 * @see \Pixiekat\HMFPSearchToolBundle\Enum\DelegationStatus
 */
final class Version20260930140000_AddPhysicianDelegations extends AbstractMigration {

  public function getDescription(): string {
    return 'Adds the physician_delegations table: accounts allowed to edit on a claimant\'s behalf.';
  }

  public function up(Schema $schema): void {
    if ($schema->hasTable('physician_delegations')) {
      $this->write("Table 'physician_delegations' already exists, skipping creation.");
      return;
    }

    // Column comments live on the entity; this is the shape.
    $this->addSql(<<<'SQL'
      CREATE TABLE physician_delegations (
        id INT AUTO_INCREMENT NOT NULL,
        claim_id INT NOT NULL,
        delegate_id INT NOT NULL,
        invited_by INT DEFAULT NULL,
        status VARCHAR(32) NOT NULL,
        open_slot TINYINT DEFAULT NULL,
        activates_account TINYINT DEFAULT 0 NOT NULL,
        token_hash VARCHAR(64) DEFAULT NULL,
        token_expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
        delegate_label VARCHAR(255) NOT NULL,
        inviter_label VARCHAR(255) NOT NULL,
        note LONGTEXT DEFAULT NULL,
        invited_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
        accepted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
        ended_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
        ended_by INT DEFAULT NULL,
        INDEX IDX_PHYSDELEG_CLAIM (claim_id),
        INDEX IDX_PHYSDELEG_DELEGATE (delegate_id),
        INDEX IDX_PHYSDELEG_INVITER (invited_by),
        INDEX IDX_PHYSDELEG_ENDER (ended_by),
        PRIMARY KEY (id)
      ) DEFAULT CHARACTER SET utf8mb4;
    SQL);

    // At most ONE open delegation per person per claim, enforced by the engine.
    //
    // open_slot is 1 while a delegation is Pending or Accepted and NULL after.
    // MariaDB/MySQL treat any unique-index row containing a NULL as distinct, so
    // finished delegations never collide — somebody can decline and later be
    // re-invited, and both rows survive as history — while a second OPEN one for
    // the same (claim, delegate) is refused. Same idea as UNIQ_PHYSCLAIM_ACTIVE,
    // in composite form.
    $this->addSql(<<<'SQL'
      CREATE UNIQUE INDEX UNIQ_PHYSDELEG_OPEN ON physician_delegations (claim_id, delegate_id, open_slot);
    SQL);

    // An invitation token must never be ambiguous between two rows.
    $this->addSql(<<<'SQL'
      CREATE UNIQUE INDEX UNIQ_PHYSDELEG_TOKEN ON physician_delegations (token_hash);
    SQL);

    // The voter's access path: "which physicians is this user a delegate for?"
    $this->addSql(<<<'SQL'
      CREATE INDEX IDX_PHYSDELEG_DELEGATE_STATUS ON physician_delegations (delegate_id, status);
    SQL);

    // CASCADE: a delegation borrows the claim's standing; with no claim row there
    // is nothing left to borrow. (Revoking a claim does NOT delete it, and so does
    // not delete these — they stay as history and simply grant nothing.)
    $this->addSql(<<<'SQL'
      ALTER TABLE physician_delegations
        ADD CONSTRAINT FK_PHYSDELEG_CLAIM
        FOREIGN KEY (claim_id) REFERENCES physician_claims (id) ON DELETE CASCADE;
    SQL);

    // CASCADE, matching physician_claims.user_id: a live grant to a deleted
    // account is a dangling permission, not history.
    $this->addSql(<<<'SQL'
      ALTER TABLE physician_delegations
        ADD CONSTRAINT FK_PHYSDELEG_DELEGATE
        FOREIGN KEY (delegate_id) REFERENCES users (id) ON DELETE CASCADE;
    SQL);

    // SET NULL for both actors: who invited, and who ended it, are history.
    $this->addSql(<<<'SQL'
      ALTER TABLE physician_delegations
        ADD CONSTRAINT FK_PHYSDELEG_INVITER
        FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL;
    SQL);

    $this->addSql(<<<'SQL'
      ALTER TABLE physician_delegations
        ADD CONSTRAINT FK_PHYSDELEG_ENDER
        FOREIGN KEY (ended_by) REFERENCES users (id) ON DELETE SET NULL;
    SQL);

    $this->write("Table 'physician_delegations' created successfully.");
  }

  public function down(Schema $schema): void {
    if (!$schema->hasTable('physician_delegations')) {
      return;
    }

    $this->write("Dropping table 'physician_delegations' — every delegate loses access.");
    $this->addSql('DROP TABLE physician_delegations');
  }
}
