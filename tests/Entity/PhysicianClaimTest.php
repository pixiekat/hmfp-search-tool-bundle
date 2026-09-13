<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Tests\Entity;

use Pixiekat\HMFPSearchToolBundle\Entity\Physician;
use Pixiekat\HMFPSearchToolBundle\Entity\PhysicianClaim;
use Pixiekat\HMFPSearchToolBundle\Entity\User;
use Pixiekat\HMFPSearchToolBundle\Enum\ClaimMethod;
use Pixiekat\HMFPSearchToolBundle\Enum\ClaimStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PhysicianClaim holds the security-relevant logic of the claim flow: the
 * confirmation token, the NPI check, and the state machine that decides whether
 * somebody may speak for a physician.
 *
 * All of it is pure, so none of it needs a database. What a database test adds on
 * top is the UNIQUE index behaviour, which cannot be observed from here — see the
 * functional test for that.
 */
final class PhysicianClaimTest extends TestCase {

  private const NPI = '1922621945';

  /**
   * A physician with an id, because syncExclusivity() mirrors it and matchesNpi()
   * reads it. Entities are normally given ids by Doctrine; setId() lets a unit
   * test skip the database without resorting to reflection.
   */
  private function physician(?string $npi = self::NPI, int $id = 10772): Physician {
    $physician = new Physician();
    $physician->setId($id);
    $physician->setLegalName('Rucci Marcus C. Foo');
    $physician->setCredentials('MD, MSc');
    $physician->setNpi($npi);

    return $physician;
  }

  private function user(string $email = 'katie@pixiekitten.net', int $id = 1): User {
    $user = new User();
    $user->setId($id);
    $user->setEmailAddress($email);

    return $user;
  }

  private function claim(?string $npi = self::NPI): PhysicianClaim {
    return new PhysicianClaim($this->physician($npi), $this->user());
  }

  // ── Construction ──────────────────────────────────────────────────────────

  public function testANewClaimIsPendingAndGrantsNothing(): void {
    $claim = $this->claim();

    $this->assertSame(ClaimStatus::Pending, $claim->getStatus());
    $this->assertFalse($claim->grantsEditing());
    $this->assertNull($claim->getMethod());
    $this->assertNull($claim->getVerifiedAt());
    $this->assertNull($claim->getDecidedBy());
    $this->assertSame(0, $claim->getAttempts());
  }

  public function testTheClaimantLabelDefaultsToTheUserIdentifier(): void {
    $this->assertSame('katie@pixiekitten.net', $this->claim()->getClaimantLabel());
  }

  public function testAnExplicitClaimantLabelWins(): void {
    $claim = new PhysicianClaim($this->physician(), $this->user(), 'Imported from the steward spreadsheet');

    $this->assertSame('Imported from the steward spreadsheet', $claim->getClaimantLabel());
  }

  /**
   * Refused rather than defaulted: an anonymous row in an audit trail looks like
   * a record while answering none of the questions the record exists for.
   */
  public function testABlankClaimantLabelIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);

    new PhysicianClaim($this->physician(), $this->user(), '   ');
  }

  // ── Tokens ────────────────────────────────────────────────────────────────

  public function testIssueTokenReturnsUsableHexAndSetsAnExpiry(): void {
    $claim = $this->claim();
    $now   = new \DateTimeImmutable('2026-09-12 12:00:00');

    $token = $claim->issueToken($now);

    // 32 random bytes, hex encoded. The route requirement is [0-9a-f]{64}, so a
    // token that does not match this shape would 404 instead of working.
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    $this->assertEquals($now->add(new \DateInterval('P2D')), $claim->getTokenExpiresAt());
  }

  /**
   * The plaintext is returned once and never stored, so two calls cannot produce
   * the same token — and the older one must stop working, which is what makes
   * "send the link again" safe.
   */
  public function testReissuingATokenInvalidatesThePreviousOne(): void {
    $claim = $this->claim();

    $first  = $claim->issueToken();
    $second = $claim->issueToken();

    $this->assertNotSame($first, $second);
    $this->assertFalse($claim->matchesToken($first));
    $this->assertTrue($claim->matchesToken($second));
  }

  public function testMatchesTokenRejectsAWrongToken(): void {
    $claim = $this->claim();
    $claim->issueToken();

    $this->assertFalse($claim->matchesToken(str_repeat('a', 64)));
  }

  public function testMatchesTokenRejectsAnExpiredToken(): void {
    $claim = $this->claim();
    $now   = new \DateTimeImmutable('2026-09-12 12:00:00');

    $token = $claim->issueToken($now);

    // One second inside the window still works; one second past it does not.
    $this->assertTrue($claim->matchesToken($token, $now->modify('+2 days -1 second')));
    $this->assertFalse($claim->matchesToken($token, $now->modify('+2 days +1 second')));
  }

  public function testMatchesTokenIsFalseWhenNoTokenWasEverIssued(): void {
    $this->assertFalse($this->claim()->matchesToken(str_repeat('b', 64)));
  }

  /**
   * A token is only a way out of Pending. Minting one for a decided claim would
   * quietly re-open it.
   */
  public function testATokenCannotBeIssuedForADecidedClaim(): void {
    $claim = $this->claim();
    $claim->verify(ClaimMethod::SelfServiceNpi);

    $this->expectException(\LogicException::class);

    $claim->issueToken();
  }

  // ── NPI matching ──────────────────────────────────────────────────────────

  #[DataProvider('npiProvider')]
  public function testMatchesNpi(string $candidate, bool $expected): void {
    $this->assertSame($expected, $this->claim()->matchesNpi($candidate));
  }

  /**
   * @return iterable<string, array{string, bool}>
   */
  public static function npiProvider(): iterable {
    yield 'exact'                 => [self::NPI, true];
    // Formatting is not a wrong answer. Somebody reading a number off a letter
    // should not burn an attempt because they typed the spaces they saw.
    yield 'with spaces'           => ['1922 6219 45', true];
    yield 'with dashes'           => ['1922-621-945', true];
    yield 'with surrounding junk' => ["  1922621945\t", true];
    yield 'wrong number'          => ['1234567890', false];
    yield 'right digits reversed' => ['5491262291', false];
    yield 'prefix only'           => ['192262194', false];
    yield 'empty'                 => ['', false];
    yield 'letters only'          => ['not an npi', false];
  }

  /**
   * The column is nullable and belongs to the export team, so a future partial
   * extract could arrive with gaps. It must fail CLOSED: an empty candidate
   * matching an empty NPI would let one person claim every incomplete record.
   */
  public function testAPhysicianWithNoNpiCannotBeClaimedByAnybody(): void {
    $claim = $this->claim(npi: null);

    $this->assertFalse($claim->matchesNpi(''));
    $this->assertFalse($claim->matchesNpi(self::NPI));
    $this->assertFalse($claim->matchesNpi('0000000000'));
  }

  // ── Attempts ──────────────────────────────────────────────────────────────

  public function testAttemptsAreAcceptedUntilTheLimitIsReached(): void {
    $claim = $this->claim();

    for ($i = 0; $i < PhysicianClaim::MAX_ATTEMPTS; $i++) {
      $this->assertTrue($claim->acceptsAttempts(), sprintf('attempt %d should be accepted', $i + 1));
      $claim->recordFailedAttempt();
    }

    $this->assertSame(PhysicianClaim::MAX_ATTEMPTS, $claim->getAttempts());
    $this->assertFalse($claim->acceptsAttempts());
  }

  public function testADecidedClaimAcceptsNoFurtherAttempts(): void {
    $claim = $this->claim();
    $claim->verify(ClaimMethod::SelfServiceNpi);

    $this->assertFalse($claim->acceptsAttempts());
  }

  // ── Transitions ───────────────────────────────────────────────────────────

  public function testVerifyGrantsEditingAndSpendsTheToken(): void {
    $claim = $this->claim();
    $token = $claim->issueToken();
    $now   = new \DateTimeImmutable('2026-09-12 23:09:51');

    $claim->verify(ClaimMethod::SelfServiceNpi, $now);

    $this->assertSame(ClaimStatus::Verified, $claim->getStatus());
    $this->assertSame(ClaimMethod::SelfServiceNpi, $claim->getMethod());
    $this->assertEquals($now, $claim->getVerifiedAt());
    $this->assertTrue($claim->grantsEditing());

    // Single use. Leaving the link live would make the email a standing
    // credential for the rest of its TTL.
    $this->assertFalse($claim->matchesToken($token));
    $this->assertNull($claim->getTokenExpiresAt());
  }

  public function testAClaimCannotBeVerifiedTwice(): void {
    $claim = $this->claim();
    $claim->verify(ClaimMethod::SelfServiceNpi);

    $this->expectException(\LogicException::class);

    $claim->verify(ClaimMethod::SelfServiceNpi);
  }

  /**
   * A steward who recognises the claimant should not have to send them off to
   * find their NPI, so Pending → Approved is a legal move.
   */
  public function testAStewardCanApproveStraightFromPending(): void {
    $claim   = $this->claim();
    $steward = $this->user('steward@bidmc.harvard.edu', 2);
    $now     = new \DateTimeImmutable('2026-09-13 09:00:00');

    $claim->approve($steward, 'Rang the department and confirmed.', $now);

    $this->assertSame(ClaimStatus::Approved, $claim->getStatus());
    $this->assertTrue($claim->grantsEditing());
    $this->assertSame(ClaimMethod::StewardManual, $claim->getMethod());
    $this->assertSame($steward, $claim->getDecidedBy());
    $this->assertEquals($now, $claim->getDecidedAt());
    $this->assertSame('Rang the department and confirmed.', $claim->getNote());

    // Never verified, but it is live now, and "since when?" needs an answer.
    $this->assertEquals($now, $claim->getVerifiedAt());
  }

  /**
   * Approving a self-verified claim confirms it without rewriting how it was
   * established — the provenance is the point of recording the method at all.
   */
  public function testApprovingAVerifiedClaimKeepsItsOriginalMethod(): void {
    $claim = $this->claim();
    $claim->verify(ClaimMethod::SelfServiceNpi, new \DateTimeImmutable('2026-09-12 23:09:51'));

    $claim->approve($this->user('steward@bidmc.harvard.edu', 2));

    $this->assertSame(ClaimStatus::Approved, $claim->getStatus());
    $this->assertSame(ClaimMethod::SelfServiceNpi, $claim->getMethod());
    $this->assertEquals(new \DateTimeImmutable('2026-09-12 23:09:51'), $claim->getVerifiedAt());
  }

  public function testRejectLeavesTheClaimGrantingNothing(): void {
    $claim   = $this->claim();
    $claim->issueToken();
    $steward = $this->user('steward@bidmc.harvard.edu', 2);

    $claim->reject($steward, 'Wrong Michael Murphy.');

    $this->assertSame(ClaimStatus::Rejected, $claim->getStatus());
    $this->assertFalse($claim->grantsEditing());
    $this->assertSame($steward, $claim->getDecidedBy());
    $this->assertNull($claim->getTokenExpiresAt());
  }

  public function testRevokeTakesAwayALiveClaim(): void {
    $claim = $this->claim();
    $claim->verify(ClaimMethod::SelfServiceNpi);

    $claim->revoke($this->user('steward@bidmc.harvard.edu', 2), 'Left the practice.');

    $this->assertSame(ClaimStatus::Revoked, $claim->getStatus());
    $this->assertFalse($claim->grantsEditing());
    $this->assertSame('Left the practice.', $claim->getNote());
  }

  /**
   * Revoking is for claims that were live. A pending claim is refused, not
   * withdrawn, and collapsing the two would lose the distinction between "never
   * good" and "was acted on then withdrawn" — which is where an investigation
   * starts.
   */
  public function testAPendingClaimCannotBeRevoked(): void {
    $this->expectException(\LogicException::class);

    $this->claim()->revoke($this->user('steward@bidmc.harvard.edu', 2));
  }

  public function testARejectedClaimCannotBeApproved(): void {
    $claim = $this->claim();
    $claim->reject($this->user('steward@bidmc.harvard.edu', 2));

    $this->expectException(\LogicException::class);

    $claim->approve($this->user('steward@bidmc.harvard.edu', 2));
  }

  /**
   * Rejecting frees the physician's slot so the right person can claim next, and
   * a re-claim is a fresh row rather than a revived one.
   */
  public function testRevokingFreesTheRecordForANewClaim(): void {
    $physician = $this->physician();

    $first = new PhysicianClaim($physician, $this->user());
    $first->verify(ClaimMethod::SelfServiceNpi);
    $first->revoke($this->user('steward@bidmc.harvard.edu', 2));

    $second = new PhysicianClaim($physician, $this->user('other@bidmc.harvard.edu', 3));
    $second->verify(ClaimMethod::SelfServiceNpi);

    $this->assertFalse($first->grantsEditing());
    $this->assertTrue($second->grantsEditing());
  }
}
