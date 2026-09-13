<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the physician claim layer: the link between a login and a provider record.
 *
 * @see \Pixiekat\HMFPSearchToolBundle\Entity\PhysicianClaim
 * @see \Pixiekat\HMFPSearchToolBundle\Enum\ClaimStatus
 */
final class Version20260912120000_AddPhysicianClaims extends AbstractMigration {

  public function getDescription(): string {
    return 'Adds the physician_claims table linking user accounts to physician records.';
  }

  public function up(Schema $schema): void {
    if ($schema->hasTable('physician_claims')) {
      $this->write("Table 'physician_claims' already exists, skipping creation.");
      return;
    }

    $this->write("Creating table 'physician_claims'...");

    // Column comments are carried in the ORM attributes rather than here; this is
    // the shape, the entity is the explanation.
    $this->addSql(<<<'SQL'
      CREATE TABLE physician_claims (
        id INT AUTO_INCREMENT NOT NULL,
        physician_id INT NOT NULL,
        user_id INT NOT NULL,
        active_claim_for INT DEFAULT NULL,
        status VARCHAR(32) NOT NULL,
        method VARCHAR(32) DEFAULT NULL,
        token_hash VARCHAR(64) DEFAULT NULL,
        token_expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
        attempts INT DEFAULT 0 NOT NULL,
        claimant_label VARCHAR(255) NOT NULL,
        note LONGTEXT DEFAULT NULL,
        claimed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
        verified_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
        decided_by INT DEFAULT NULL,
        decided_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
        INDEX IDX_PHYSCLAIM_PHYSICIAN (physician_id),
        INDEX IDX_PHYSCLAIM_USER (user_id),
        INDEX IDX_PHYSCLAIM_DECIDER (decided_by),
        PRIMARY KEY (id)
      ) DEFAULT CHARACTER SET utf8mb4;
    SQL);

    // The whole point of the table, and the one constraint that must be enforced
    // by the engine rather than by application code.
    //
    // active_claim_for mirrors physician_id while a claim grants editing and is
    // NULL the rest of the time. MySQL and MariaDB allow unlimited NULLs in a
    // unique index, so this reads as: any number of pending or refused claims per
    // physician, but at most ONE that is live.
    //
    // A SELECT-then-INSERT check in PHP would have a race between the two
    // statements, and this is the one feature where the race is likely rather
    // than theoretical — the contested case is two physicians with the same name,
    // who will plausibly be prompted to claim by the same email on the same
    // morning. Here the second writer simply loses, loudly, and the controller
    // turns that into a contested-claim notice for a steward.
    $this->addSql(<<<'SQL'
      CREATE UNIQUE INDEX UNIQ_PHYSCLAIM_ACTIVE ON physician_claims (active_claim_for);
    SQL);

    // A confirmation token must never be ambiguous between two claims. Nullable,
    // so spent and unissued tokens (both NULL) do not collide with each other.
    $this->addSql(<<<'SQL'
      CREATE UNIQUE INDEX UNIQ_PHYSCLAIM_TOKEN ON physician_claims (token_hash);
    SQL);

    // The stewards' queue: open claims, oldest first. Same shape as
    // IDX_PHYSEDIT_QUEUE on physician_edits, for the same reason.
    $this->addSql(<<<'SQL'
      CREATE INDEX IDX_PHYSCLAIM_QUEUE ON physician_claims (status, claimed_at);
    SQL);

    // The voter's access path — "does this user hold a granting claim?" — asked
    // on every permission check against a physician, in this column order.
    $this->addSql(<<<'SQL'
      CREATE INDEX IDX_PHYSCLAIM_USER_STATUS ON physician_claims (user_id, status);
    SQL);

    // CASCADE: a claim on a deleted physician grants nothing to nobody.
    $this->addSql(<<<'SQL'
      ALTER TABLE physician_claims
        ADD CONSTRAINT FK_PHYSCLAIM_PHYSICIAN
        FOREIGN KEY (physician_id) REFERENCES physicians (id) ON DELETE CASCADE;
    SQL);

    // CASCADE, unlike physician_edits.edited_by which is SET NULL. An edit is
    // history that must outlive its author; a claim is a live permission, and a
    // permission granted to a deleted account is not history, it is a dangling
    // grant. The audit log keeps the narrative.
    $this->addSql(<<<'SQL'
      ALTER TABLE physician_claims
        ADD CONSTRAINT FK_PHYSCLAIM_USER
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
    SQL);

    // SET NULL, matching physician_edits.reviewed_by: losing the decider's name
    // is regrettable, losing the record that a decision happened is a hole.
    $this->addSql(<<<'SQL'
      ALTER TABLE physician_claims
        ADD CONSTRAINT FK_PHYSCLAIM_DECIDER
        FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL;
    SQL);

    $this->write("Table 'physician_claims' created successfully.");
  }

  public function down(Schema $schema): void {
    if (!$schema->hasTable('physician_claims')) {
      $this->write("Table 'physician_claims' does not exist, skipping.");
      return;
    }

    $this->write("Dropping table 'physician_claims' — every account/physician link is lost.");
    $this->addSql('DROP TABLE physician_claims');
  }
}
