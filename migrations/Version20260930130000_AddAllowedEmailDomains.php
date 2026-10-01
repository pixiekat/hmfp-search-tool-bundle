<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the allowlist of email domains that may be given local accounts.
 *
 * Starts EMPTY, deliberately — see the note in up().
 *
 * @see \Pixiekat\HMFPSearchToolBundle\Entity\AllowedEmailDomain
 */
final class Version20260930130000_AddAllowedEmailDomains extends AbstractMigration {

  public function getDescription(): string {
    return 'Adds allowed_email_domains, the admin-managed list of domains eligible for local accounts.';
  }

  public function up(Schema $schema): void {
    if ($schema->hasTable('allowed_email_domains')) {
      $this->write("Table 'allowed_email_domains' already exists, skipping creation.");
      return;
    }

    $this->addSql(<<<'SQL'
      CREATE TABLE allowed_email_domains (
        id INT AUTO_INCREMENT NOT NULL,
        domain VARCHAR(253) NOT NULL,
        note VARCHAR(255) DEFAULT NULL,
        added_by INT DEFAULT NULL,
        added_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
        UNIQUE INDEX UNIQ_ALLOWDOMAIN_DOMAIN (domain),
        INDEX IDX_ALLOWDOMAIN_ADDER (added_by),
        PRIMARY KEY (id)
      ) DEFAULT CHARACTER SET utf8mb4;
    SQL);

    // SET NULL: removing an administrator must not remove the domains they added.
    $this->addSql(<<<'SQL'
      ALTER TABLE allowed_email_domains
        ADD CONSTRAINT FK_ALLOWDOMAIN_ADDER
        FOREIGN KEY (added_by) REFERENCES users (id) ON DELETE SET NULL;
    SQL);

    // No seed rows. An allowlist is a list of decisions, and a migration is the
    // wrong place to make them: a seeded domain would arrive on every install,
    // including ones for a different organisation, with nobody's name against it.
    // An empty list fails CLOSED — nobody can be invited until an administrator
    // adds a domain at /admincp/allowed-domains — and the invite page says so.
    $this->write("Table 'allowed_email_domains' created. It is empty: add domains at /admincp/allowed-domains.");
  }

  public function down(Schema $schema): void {
    if (!$schema->hasTable('allowed_email_domains')) {
      return;
    }

    $this->addSql('DROP TABLE allowed_email_domains');
  }
}
