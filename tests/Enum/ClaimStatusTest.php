<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Tests\Enum;

use Pixiekat\HMFPSearchToolBundle\Enum\ClaimStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ClaimStatus is the policy switch for the whole claim feature, so these tests
 * exist to PIN the policy rather than to check that an enum works.
 *
 * If somebody decides email-plus-NPI is not enough and drops Verified from
 * grantsEditing(), the first test below fails and says so — which is the point.
 * A failing assertion here is not a bug, it is the deliberate decision being
 * noticed. Update the expectation and move on.
 */
final class ClaimStatusTest extends TestCase {

  /**
   * Which statuses let a claimant propose edits.
   */
  #[DataProvider('editingProvider')]
  public function testGrantsEditing(ClaimStatus $status, bool $expected): void {
    $this->assertSame($expected, $status->grantsEditing());
  }

  /**
   * @return iterable<string, array{ClaimStatus, bool}>
   */
  public static function editingProvider(): iterable {
    // Pending grants nothing. This is the one that matters most: it is what
    // makes it safe for two people to hold a pending claim on the same
    // physician, since only a granting status takes the exclusive slot.
    yield 'pending grants nothing'  => [ClaimStatus::Pending, false];
    yield 'verified grants editing' => [ClaimStatus::Verified, true];
    yield 'approved grants editing' => [ClaimStatus::Approved, true];
    yield 'rejected grants nothing' => [ClaimStatus::Rejected, false];
    yield 'revoked grants nothing'  => [ClaimStatus::Revoked, false];
  }

  /**
   * grantingValues() feeds every query that asks "who may edit this?", so it
   * must agree with grantsEditing() rather than being a second list that can
   * drift away from it.
   */
  public function testGrantingValuesMatchesGrantsEditing(): void {
    $expected = array_values(array_map(
      static fn (ClaimStatus $case): string => $case->value,
      array_filter(ClaimStatus::cases(), static fn (ClaimStatus $case): bool => $case->grantsEditing()),
    ));

    $this->assertSame($expected, ClaimStatus::grantingValues());
    $this->assertSame(['verified', 'approved'], ClaimStatus::grantingValues());
  }

  /**
   * Which statuses still need somebody to act.
   */
  #[DataProvider('openProvider')]
  public function testIsOpen(ClaimStatus $status, bool $expected): void {
    $this->assertSame($expected, $status->isOpen());
  }

  /**
   * @return iterable<string, array{ClaimStatus, bool}>
   */
  public static function openProvider(): iterable {
    yield 'pending is open'   => [ClaimStatus::Pending, true];
    // Verified is open even though it already grants editing: a self-service
    // claim should still cross a steward's desk, it just is not made to wait
    // there first.
    yield 'verified is open'  => [ClaimStatus::Verified, true];
    yield 'approved is done'  => [ClaimStatus::Approved, false];
    yield 'rejected is done'  => [ClaimStatus::Rejected, false];
    yield 'revoked is done'   => [ClaimStatus::Revoked, false];
  }

  /**
   * isFinal() gates the approve() transition, so the distinction between
   * "finished with" and "merely decided" has to hold: Approved is decided but
   * NOT final, because it can still be revoked.
   */
  #[DataProvider('finalProvider')]
  public function testIsFinal(ClaimStatus $status, bool $expected): void {
    $this->assertSame($expected, $status->isFinal());
  }

  /**
   * @return iterable<string, array{ClaimStatus, bool}>
   */
  public static function finalProvider(): iterable {
    yield 'pending not final'      => [ClaimStatus::Pending, false];
    yield 'verified not final'     => [ClaimStatus::Verified, false];
    yield 'approved not final'     => [ClaimStatus::Approved, false];
    yield 'rejected is final'      => [ClaimStatus::Rejected, true];
    yield 'revoked is final'       => [ClaimStatus::Revoked, true];
  }

  /**
   * The per-case predicates the admin template gates its buttons on.
   *
   * Each must be true for exactly its own case — a predicate that answered true
   * for two would put a revoke button on a row that cannot be revoked, which is
   * the failure the controller guards had to be added for.
   */
  public function testEachPredicateMatchesOnlyItsOwnCase(): void {
    $predicates = [
      'isPending'  => ClaimStatus::Pending,
      'isVerified' => ClaimStatus::Verified,
      'isApproved' => ClaimStatus::Approved,
      'isRejected' => ClaimStatus::Rejected,
      'isRevoked'  => ClaimStatus::Revoked,
    ];

    // Every case is named by exactly one predicate, so nothing is unreachable
    // from a template.
    self::assertCount(count(ClaimStatus::cases()), $predicates);

    foreach ($predicates as $method => $owner) {
      foreach (ClaimStatus::cases() as $case) {
        self::assertSame(
          $case === $owner,
          $case->{$method}(),
          sprintf('%s() on %s', $method, $case->value),
        );
      }
    }
  }

  /**
   * Every case needs a label, because the review queue and the profile page
   * render it. A missing arm in the match would be a runtime error on a page
   * nobody looks at until a claim is refused.
   */
  public function testEveryCaseHasANonEmptyLabel(): void {
    foreach (ClaimStatus::cases() as $case) {
      $this->assertNotSame('', trim($case->label()), sprintf('%s has no label', $case->value));
    }
  }

  /**
   * The stored strings are the database's vocabulary. Changing one silently
   * orphans every existing row, so they are pinned here explicitly.
   */
  public function testStoredValuesAreStable(): void {
    $this->assertSame([
      'pending',
      'verified',
      'approved',
      'rejected',
      'revoked',
    ], array_map(static fn (ClaimStatus $c): string => $c->value, ClaimStatus::cases()));
  }
}
