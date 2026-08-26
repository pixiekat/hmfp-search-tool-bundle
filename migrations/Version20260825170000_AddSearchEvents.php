<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the search analytics event table.
 */
final class Version20260825170000_AddSearchEvents extends AbstractMigration {

  public function getDescription(): string {
    return 'Adds the search_events table for aggregate search analytics.';
  }

  public function up(Schema $schema): void {
    if ($schema->hasTable('search_events')) {
      $this->write("Table 'search_events' already exists, skipping.");
      return;
    }

    $this->write("Creating table 'search_events'...");

    // BIGINT id: a busy directory produces millions of rows over a few years
    // and INT runs out at 2.1 billion. Four bytes a row against an outage at
    // an arbitrary future moment is not a close call.
    //
    // matched_term_id has NO foreign key on purpose. A FK would either cascade
    // — destroying history whenever the taxonomy is tidied — or SET NULL,
    // losing which term was searched for. The name is snapshotted alongside
    // instead, so reports survive the vocabulary being reorganised.
    $this->addSql(<<<'SQL'
      CREATE TABLE search_events (
        id BIGINT AUTO_INCREMENT NOT NULL,
        occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
        term VARCHAR(100) DEFAULT NULL,
        matched_term_id INT DEFAULT NULL,
        matched_term_name VARCHAR(255) DEFAULT NULL,
        matched_vocabulary VARCHAR(64) DEFAULT NULL,
        result_count INT NOT NULL,
        filters_used VARCHAR(255) DEFAULT NULL,
        correlation_id VARCHAR(32) NOT NULL,
        PRIMARY KEY (id)
      ) DEFAULT CHARACTER SET utf8mb4;
    SQL);

    // Every report is "what happened between these dates", so occurred_at
    // leads each index; the second column narrows the questions actually asked.
    $this->addSql('CREATE INDEX IDX_SEARCH_EVENT_TIME ON search_events (occurred_at)');
    $this->addSql('CREATE INDEX IDX_SEARCH_EVENT_TERM ON search_events (occurred_at, term)');
    $this->addSql('CREATE INDEX IDX_SEARCH_EVENT_MATCH ON search_events (occurred_at, matched_term_id)');

    // Not unique: a correlation id is shared by one search and the clicks that
    // follow it, so the click tables will carry the same value.
    $this->addSql('CREATE INDEX IDX_SEARCH_EVENT_CORRELATION ON search_events (correlation_id)');

    $this->write("Table 'search_events' created successfully.");
  }

  public function down(Schema $schema): void {
    if (!$schema->hasTable('search_events')) {
      return;
    }

    // Analytics history only. Nothing else depends on it and no user-facing
    // behaviour changes — but it cannot be reconstructed, so export first if
    // the numbers matter.
    $this->write("Dropping table 'search_events' — analytics history is lost.");
    $this->addSql('DROP TABLE search_events');
  }
}
