<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Tests\Entity;

use Pixiekat\HMFPSearchToolBundle\Entity\AllowedEmailDomain;
use Pixiekat\HMFPSearchToolBundle\Services\EmailDomainPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Domain normalisation: forgiving about what people paste, strict about what
 * is stored.
 */
final class AllowedEmailDomainTest extends TestCase {

  /**
   * @return iterable<string, array{string, string}>
   */
  public static function acceptable(): iterable {
    yield 'plain'              => ['bidmc.harvard.edu', 'bidmc.harvard.edu'];
    yield 'capitals'           => ['BIDMC.Harvard.EDU', 'bidmc.harvard.edu'];
    yield 'leading @'          => ['@bidmc.harvard.edu', 'bidmc.harvard.edu'];
    yield 'whole address'      => ['jane.doe@bidmc.harvard.edu', 'bidmc.harvard.edu'];
    yield 'surrounding spaces' => ['  bidmc.harvard.edu  ', 'bidmc.harvard.edu'];
    yield 'trailing dot'       => ['bidmc.harvard.edu.', 'bidmc.harvard.edu'];
    yield 'hyphenated label'   => ['beth-israel.org', 'beth-israel.org'];
  }

  #[DataProvider('acceptable')]
  public function testNormalises(string $typed, string $stored): void {
    $this->assertSame($stored, AllowedEmailDomain::normaliseDomain($typed));
    $this->assertSame($stored, (new AllowedEmailDomain($typed))->getDomain());
  }

  /**
   * @return iterable<string, array{string}>
   */
  public static function unacceptable(): iterable {
    yield 'empty'            => [''];
    yield 'no dot'           => ['localhost'];
    yield 'space inside'     => ['bidmc harvard.edu'];
    yield 'leading hyphen'   => ['-bidmc.harvard.edu'];
    yield 'empty label'      => ['bidmc..harvard.edu'];
    yield 'wildcard'         => ['*.harvard.edu'];
    yield 'url'              => ['https://bidmc.harvard.edu'];
  }

  #[DataProvider('unacceptable')]
  public function testRefuses(string $typed): void {
    $this->assertNull(AllowedEmailDomain::normaliseDomain($typed));
  }

  public function testTheConstructorRefusesWhatCannotBeADomain(): void {
    $this->expectException(\InvalidArgumentException::class);
    new AllowedEmailDomain('not a domain');
  }

  /**
   * The policy's half: an address must contain '@' — a bare domain typed into
   * an email box is a mistake, not an address on that domain.
   */
  public function testThePolicyOnlyReadsDomainsOffAddresses(): void {
    $this->assertSame('bidmc.harvard.edu', EmailDomainPolicy::domainOf('Jane@BIDMC.harvard.edu'));
    $this->assertNull(EmailDomainPolicy::domainOf('bidmc.harvard.edu'));
  }
}
