<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Services;

use Pixiekat\HMFPSearchToolBundle\Entity\AllowedEmailDomain;
use Pixiekat\HMFPSearchToolBundle\Repository\AllowedEmailDomainRepository;

/**
 * Answers "may this address be given a LOCAL account here?"
 *
 * One service rather than a repository call scattered through controllers, so
 * that when local registration is switched on it asks the same question the
 * delegate invitations do, in the same words, and gets the same answer.
 *
 * Not consulted by Entra SSO — see the note on AllowedEmailDomain.
 *
 * Fails CLOSED: an empty allowlist admits nobody. The alternative — "no list
 * means no restriction" — turns forgetting to configure it into the most
 * permissive setting there is.
 */
class EmailDomainPolicy {

  public function __construct(
    private readonly AllowedEmailDomainRepository $domains,
  ) {  }

  /**
   * Whether $email is on an allowlisted domain.
   */
  public function isAllowed(string $email): bool {
    $domain = self::domainOf($email);

    return $domain !== null && $this->domains->isListed($domain);
  }

  /**
   * The normalised domain of an address, or null if $email is not an address.
   *
   * Requires an '@' — unlike AllowedEmailDomain::normaliseDomain(), which also
   * accepts a bare domain because that is what an administrator types. Here the
   * input is somebody's email address, and a bare domain in that box is a
   * mistake to report rather than a value to accept.
   */
  public static function domainOf(string $email): ?string {
    if (!str_contains($email, '@')) {
      return null;
    }

    return AllowedEmailDomain::normaliseDomain($email);
  }

  /**
   * The allowlisted domains, for the hint beside the invite form.
   *
   * Showing the list is a kindness rather than a leak: anybody who can see the
   * form is signed in, and "addresses at bidmc.harvard.edu" saves them sending
   * an invitation to a personal Gmail and wondering why it was refused.
   *
   * @return list<string>
   */
  public function listedDomains(): array {
    return array_map(
      static fn (AllowedEmailDomain $d): string => $d->getDomain(),
      $this->domains->findAllOrdered(),
    );
  }
}
