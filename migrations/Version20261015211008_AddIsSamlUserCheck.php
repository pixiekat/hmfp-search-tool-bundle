<?php

declare(strict_types=1);

namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015211008_AddIsSamlUserCheck extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the is_saml_user column to the users table to indicate if a user is a SAML user.';
    }

    public function up(Schema $schema): void
    {
      // does the users table exist?
      if (!$schema->hasTable('users')) {
          $this->abortIf(true, 'The users table does not exist.');
      }

      // does the is_saml_user column already exist?
      if ($schema->getTable('users')->hasColumn('is_saml_user')) {
          $this->write("Column 'is_saml_user' already exists in the 'users' table, skipping creation.");
          return;
      }

      $this->addSql('ALTER TABLE users ADD is_saml_user TINYINT(1) DEFAULT 0 NOT NULL');
      $this->write("Column 'is_saml_user' added to the 'users' table.");
    }

    public function down(Schema $schema): void
    {
        // does the users table exist?
        if (!$schema->hasTable('users')) {
            $this->write("The users table does not exist, skipping removal of 'is_saml_user' column.");
            return;
        }

        // does the is_saml_user column exist?
        if (!$schema->getTable('users')->hasColumn('is_saml_user')) {
            $this->write("Column 'is_saml_user' does not exist in the 'users' table, skipping removal.");
            return;
        }

        $this->addSql('ALTER TABLE users DROP COLUMN is_saml_user');
        $this->write("Column 'is_saml_user' removed from the 'users' table.");
    }
}
