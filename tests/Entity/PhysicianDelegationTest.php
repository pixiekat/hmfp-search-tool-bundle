<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Tests\Entity;

use Pixiekat\HMFPSearchToolBundle\Entity\Physician;
use Pixiekat\HMFPSearchToolBundle\Entity\PhysicianClaim;
use Pixiekat\HMFPSearchToolBundle\Entity\PhysicianDelegation;
use Pixiekat\HMFPSearchToolBundle\Entity\User;
use Pixiekat\HMFPSearchToolBundle\Enum\ClaimMethod;
use Pixiekat\HMFPSearchToolBundle\Enum\DelegationStatus;
use PHPUnit\Framework\TestCase;

/**
 * PhysicianDelegation's state machine, token, and account-activation rule.
 *
 * Pure, so no database. What only a database can show — the UNIQUE index on
 * open_slot, and the voter's join through the claim — is in the functional
 * DelegationFlowTest.
 */
final class PhysicianDelegationTest extends TestCase {

  private function user(string $email, int $id): User {
    $user = new User();
    $user->setId($id);
    $user->setEmailAddress($email);

    return $user;
  }

  private function liveClaim(): PhysicianClaim {
    $physician = new Physician();
    $physician->setId(10772);
    $physician->setLegalName('Rucci Marcus C. Foo');
    $physician->setNpi('1922621945');

    $claim = new PhysicianClaim($physician, $this->user('claimant@bidmc.harvard.edu', 1));
    $claim->verify(ClaimMethod::SelfServiceNpi);

    return $claim;
  }

  private function delegation(?User $delegate = null, bool $activatesAccount = false): PhysicianDelegation {
    $claim = $this->liveClaim();

    return new PhysicianDelegation(
      $claim,
      $delegate ?? $this->user('assistant@bidmc.harvard.edu', 2),
      $claim->getUser(),
      $activatesAccount,
    );
  }

  // ── Construction ──────────────────────────────────────────────────────────

  public function testANewDelegationIsPendingAndGrantsNothing(): void {
    $delegation = $this->delegation();

    $this->assertSame(DelegationStatus::Pending, $delegation->getStatus());
    $this->assertFalse($delegation->grantsEditing());
    $this->assertSame('assistant@bidmc.harvard.edu', $delegation->getDelegateLabel());
    $this->assertSame('claimant@bidmc.harvard.edu', $delegation->getInviterLabel());
  }

  public function testCannotDelegateFromAClaimThatIsNotLive(): void {
    $physician = new Physician();
    $physician->setId(1);
    $pending = new PhysicianClaim($physician, $this->user('claimant@bidmc.harvard.edu', 1));

    $this->expectException(\LogicException::class);
    new PhysicianDelegation($pending, $this->user('assistant@bidmc.harvard.edu', 2), $pending->getUser());
  }

  public function testCannotDelegateToYourself(): void {
    $claim = $this->liveClaim();

    $this->expectException(\InvalidArgumentException::class);
    new PhysicianDelegation($claim, $claim->getUser(), $claim->getUser());
  }

  // ── Tokens ────────────────────────────────────────────────────────────────

  public function testATokenMatchesUntilItExpires(): void {
    $delegation = $this->delegation();
    $now        = new \DateTimeImmutable('2026-09-30 12:00');
    $token      = $delegation->issueToken($now);

    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    $this->assertTrue($delegation->matchesToken($token, $now->modify('+6 days')));
    $this->assertFalse($delegation->matchesToken($token, $now->modify('+8 days')), 'seven-day TTL');
    $this->assertFalse($delegation->matchesToken(str_repeat('0', 64), $now));
  }

  public function testReissuingKillsTheOldLink(): void {
    $delegation = $this->delegation();
    $first      = $delegation->issueToken();
    $second     = $delegation->issueToken();

    $this->assertFalse($delegation->matchesToken($first));
    $this->assertTrue($delegation->matchesToken($second));
  }

  public function testAnsweringSpendsTheToken(): void {
    foreach (['accept', 'decline'] as $answer) {
      $delegation = $this->delegation();
      $token      = $delegation->issueToken();

      $delegation->{$answer}();

      $this->assertFalse($delegation->matchesToken($token), $answer . ' must spend the link');
    }
  }

  // ── Transitions ───────────────────────────────────────────────────────────

  public function testAcceptingGrantsEditingWhileTheClaimIsLive(): void {
    $delegation = $this->delegation();
    $delegation->accept();

    $this->assertSame(DelegationStatus::Accepted, $delegation->getStatus());
    $this->assertNotNull($delegation->getAcceptedAt());
    $this->assertTrue($delegation->grantsEditing());
  }

  /**
   * The property the whole design rests on: the delegation is worth nothing
   * once the claim it borrows from is gone, without anybody touching its row.
   */
  public function testRevokingTheClaimCutsOffAnAcceptedDelegate(): void {
    $delegation = $this->delegation();
    $delegation->accept();

    $delegation->getClaim()->revoke($this->user('steward@bidmc.harvard.edu', 9));

    $this->assertSame(DelegationStatus::Accepted, $delegation->getStatus(), 'row untouched');
    $this->assertFalse($delegation->grantsEditing());
  }

  public function testDecliningIsOnlyPossibleWhilePending(): void {
    $delegation = $this->delegation();
    $delegation->accept();

    $this->expectException(\LogicException::class);
    $delegation->decline();
  }

  public function testRevokeWorksFromPendingAndAcceptedButNotTwice(): void {
    $steward = $this->user('steward@bidmc.harvard.edu', 9);

    $pending = $this->delegation();
    $pending->revoke($steward, 'withdrawn');
    $this->assertSame(DelegationStatus::Revoked, $pending->getStatus());
    $this->assertNull($pending->getAcceptedAt());

    $accepted = $this->delegation();
    $accepted->accept();
    $accepted->revoke($steward);
    $this->assertSame(DelegationStatus::Revoked, $accepted->getStatus());
    $this->assertNotNull($accepted->getAcceptedAt(), 'acceptedAt survives revoke — revert depends on it');
    $this->assertSame($steward, $accepted->getEndedBy());

    $this->expectException(\LogicException::class);
    $accepted->revoke($steward);
  }

  public function testATokenCannotBeIssuedOnceAnswered(): void {
    $delegation = $this->delegation();
    $delegation->decline();

    $this->expectException(\LogicException::class);
    $delegation->issueToken();
  }

  // ── Account activation ────────────────────────────────────────────────────

  public function testAnInvitationThatMadeTheAccountMayActivateIt(): void {
    $invitee = $this->user('new.person@bidmc.harvard.edu', 3);
    $invitee->deactivate();

    $this->assertTrue($this->delegation($invitee, activatesAccount: true)->canActivateAccount());
  }

  /**
   * The hole this rule exists to close: an invitation must never be a way to
   * reset somebody's password or switch a deactivated account back on.
   */
  public function testAnInvitationNeverActivatesAnAccountItDidNotMake(): void {
    $deactivated = $this->user('switched.off@bidmc.harvard.edu', 4);
    $deactivated->deactivate();

    $this->assertFalse($this->delegation($deactivated, activatesAccount: false)->canActivateAccount());
  }

  public function testOnceActivatedTheLinkCannotTouchTheAccountAgain(): void {
    $invitee = $this->user('new.person@bidmc.harvard.edu', 3);
    $invitee->deactivate();
    $delegation = $this->delegation($invitee, activatesAccount: true);

    $invitee->setPassword('$2y$04$somehash');
    $this->assertFalse($delegation->canActivateAccount(), 'has a password now');

    $invitee->activate();
    $this->assertFalse($delegation->canActivateAccount(), 'and is active');
  }
}
