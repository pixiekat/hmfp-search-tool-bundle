<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Tests\Enum;

use Pixiekat\HMFPSearchToolBundle\Enum\DelegationStatus;
use PHPUnit\Framework\TestCase;

final class DelegationStatusTest extends TestCase {

  /**
   * Only Accepted grants editing. A change to this list is a change to who may
   * edit somebody else's profile, so it should fail a test on purpose.
   */
  public function testOnlyAcceptedGrantsEditing(): void {
    $granting = array_values(array_filter(
      DelegationStatus::cases(),
      static fn (DelegationStatus $s): bool => $s->grantsEditing(),
    ));

    $this->assertSame([DelegationStatus::Accepted], $granting);
  }

  /**
   * Open drives the UNIQUE index: exactly these two occupy the slot.
   */
  public function testPendingAndAcceptedAreOpenAndTheRestAreFinal(): void {
    foreach (DelegationStatus::cases() as $status) {
      $expectedOpen = in_array($status, [DelegationStatus::Pending, DelegationStatus::Accepted], true);

      $this->assertSame($expectedOpen, $status->isOpen(), $status->value);
      $this->assertSame(!$expectedOpen, $status->isFinal(), $status->value);
    }
  }

  public function testEveryCaseHasALabel(): void {
    foreach (DelegationStatus::cases() as $status) {
      $this->assertNotSame('', $status->label());
    }
  }
}
