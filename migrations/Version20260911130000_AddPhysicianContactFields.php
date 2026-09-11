<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds physician contact fields.
 */
final class Version20260911130000_AddPhysicianContactFields extends AbstractMigration {

  /** Column → definition. Kept in step with the ORM attributes on Physician. */
  private const COLUMNS = [
    'npi'                 => 'VARCHAR(10) DEFAULT NULL',
    'gender'              => 'VARCHAR(8) DEFAULT NULL',
    'phone'               => 'VARCHAR(32) DEFAULT NULL',
    'preferred_full_name' => 'VARCHAR(255) DEFAULT NULL',
  ];

  public function getDescription(): string {
    return 'Adds physician contact fields.';
  }

public function up(Schema $schema): void {
  $table = $schema->getTable('physicians');

  foreach (self::COLUMNS as $column => $definition) {
    if ($table->hasColumn($column)) {
      $this->write("Column `{$column}` already exists, skipping.");
      continue;
    }
    $this->addSql("ALTER TABLE physicians ADD {$column} {$definition}");
  }

  if (!$table->hasIndex('UNIQ_PHYSICIAN_NPI')) {
    $this->addSql('CREATE UNIQUE INDEX UNIQ_PHYSICIAN_NPI ON physicians (npi)');
  }
}

  public function down(Schema $schema): void {
    if (!$schema->hasTable('physicians')) {
      $this->write("Table 'physicians' does not exist, skipping.");
      return;
    }

    $table = $schema->getTable('physicians');
    foreach (self::COLUMNS as $column => $definition) {
      if (!$table->hasColumn($column)) {
        $this->write("Column `{$column}` does not exist, skipping.");
        continue;
      }
      $this->addSql("ALTER TABLE physicians DROP {$column}");
    }
  }
}
