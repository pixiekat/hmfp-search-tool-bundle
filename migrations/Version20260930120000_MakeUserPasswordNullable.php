<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets an account exist without a local password.
 *
 * Needed by delegate invitations (an invited person's account exists before
 * they have chosen a password) and, later, by Entra SSO accounts that never
 * have one. A NULL hash cannot be used to sign in — see the note on
 * \Pixiekat\HMFPSearchToolBundle\Entity\User.
 *
 * @see \Pixiekat\HMFPSearchToolBundle\Entity\User
 */
final class Version20260930120000_MakeUserPasswordNullable extends AbstractMigration {

  public function getDescription(): string {
    return 'Makes users.password nullable, for invited and (later) SSO accounts.';
  }

  public function up(Schema $schema): void {
    $this->addSql('ALTER TABLE users MODIFY password VARCHAR(255) DEFAULT NULL');
  }

  public function down(Schema $schema): void {
    // Refuses rather than guessing. Putting NOT NULL back would fail on any
    // account without a password, and "fixing" that by writing a placeholder
    // hash would invent credentials. Whoever rolls this back has to decide what
    // those accounts should become — delete them, or set real passwords — first.
    $this->abortIf(
      (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users WHERE password IS NULL') > 0,
      'Some accounts have no password (invited delegates or SSO users). Resolve them before rolling back.',
    );

    $this->addSql('ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL');
  }
}
