<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the auth code field to the users table.
 */
final class Version20260821180000_AddAuthCodeFieldToUsers extends AbstractMigration {

  public function getDescription(): string {
    return 'Adds the auth code field to the users table.';
  }

  public function up(Schema $schema): void {
    if (!$schema->hasTable('users')) {
      $this->write("Table 'users' does not exist, skipping auth_code change.");
    }
    elseif ($schema->getTable('users')->hasColumn('auth_code')) {
      $this->write("Column 'auth_code' already exists, skipping.");
    }
    else {
      $this->write("Making 'user.auth_code'...");
      $this->addSql(<<<'SQL'
        ALTER TABLE users ADD auth_code VARCHAR(20) DEFAULT NULL;
      SQL);
      $this->write("Added auth_code to users table successfully.");
    }
  }

  public function down(Schema $schema): void {
    if (!$schema->hasTable('users')) {
      $this->write("Table 'users' does not exist, skipping.");
    }
    else {
      $this->write("Dropping field `auth_code` on 'users'...");
      $this->addSql(<<<'SQL'
        ALTER TABLE users DROP auth_code;
      SQL);
    }
  }
}
