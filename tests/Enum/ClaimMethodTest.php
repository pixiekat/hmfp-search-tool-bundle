<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Tests\Enum;

use Pixiekat\HMFPSearchToolBundle\Enum\ClaimMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ClaimMethod records how much a claim is worth trusting.
 *
 * As with ClaimStatusTest, these pin decisions rather than verify that PHP enums
 * function. The important one is allowsImmediatePublication(): nothing reads it
 * yet, and a test is how it stays correct until something does.
 */
final class ClaimMethodTest extends TestCase {

  /**
   * Self-service claims still reach the stewards' queue; a steward who granted
   * the claim does not need to review their own decision.
   */
  #[DataProvider('reviewProvider')]
  public function testRequiresReview(ClaimMethod $method, bool $expected): void {
    $this->assertSame($expected, $method->requiresReview());
  }

  /**
   * @return iterable<string, array{ClaimMethod, bool}>
   */
  public static function reviewProvider(): iterable {
    yield 'self-service needs a look' => [ClaimMethod::SelfServiceNpi, true];
    yield 'steward vouched already'   => [ClaimMethod::StewardManual, false];
    yield 'sso asserted it'           => [ClaimMethod::SsoAttribute, false];
  }

  /**
   * Whether edits from a claimant verified this way may go live unreviewed.
   *
   * The single most consequential line in the enum, and currently unread by
   * production code — PhysicianEditManager::propose() publishes everything
   * immediately. It exists so that when propose() grows a review-first path, the
   * answer is already written down: a self-service claimant must not be able to
   * publish under somebody's name without a human in between, because "a
   * physician is surprised by something published as them" is the failure the
   * whole feature exists to prevent.
   */
  #[DataProvider('publicationProvider')]
  public function testAllowsImmediatePublication(ClaimMethod $method, bool $expected): void {
    $this->assertSame($expected, $method->allowsImmediatePublication());
  }

  /**
   * @return iterable<string, array{ClaimMethod, bool}>
   */
  public static function publicationProvider(): iterable {
    yield 'self-service must be reviewed first' => [ClaimMethod::SelfServiceNpi, false];
    yield 'steward-verified may publish'        => [ClaimMethod::StewardManual, true];
    yield 'sso-verified may publish'            => [ClaimMethod::SsoAttribute, true];
  }

  /**
   * active() is the honest list: what a person can actually obtain today, as
   * opposed to what the enum documents as intended. SsoAttribute is unreachable
   * until the identity provider releases an attribute that joins to the extract.
   */
  public function testActiveExcludesTheUnreachableMethod(): void {
    $this->assertSame(
      [ClaimMethod::SelfServiceNpi, ClaimMethod::StewardManual],
      ClaimMethod::active(),
    );
    $this->assertNotContains(ClaimMethod::SsoAttribute, ClaimMethod::active());
  }

  public function testEveryCaseHasANonEmptyLabel(): void {
    foreach (ClaimMethod::cases() as $case) {
      $this->assertNotSame('', trim($case->label()), sprintf('%s has no label', $case->value));
    }
  }

  /**
   * Stored strings are the database's vocabulary — pinned, as in ClaimStatus.
   */
  public function testStoredValuesAreStable(): void {
    $this->assertSame([
      'self_service_npi',
      'steward_manual',
      'sso_attribute',
    ], array_map(static fn (ClaimMethod $c): string => $c->value, ClaimMethod::cases()));
  }
}
