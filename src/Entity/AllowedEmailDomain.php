<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;

/**
 * One email domain whose addresses may be given LOCAL accounts here.
 *
 * Managed from the control panel (/admincp/allowed-domains) rather than an env
 * var, because the people who know which hospital domains belong to the
 * organisation are administrators, not whoever can redeploy the app.
 *
 * ── What consults it ──────────────────────────────────────────────────────
 *   - Delegate invitations: only allowlisted addresses may be invited, so the
 *     invite form cannot be used to create accounts for — or send mail to —
 *     arbitrary addresses.
 *   - Local self-registration, when that is switched on (it is not yet; see
 *     App\Controller\UserController::register()).
 *
 * ── What deliberately does NOT ─────────────────────────────────────────────
 * Entra SSO. Whoever the tenant lets sign in has already been vetted by the
 * tenant, and a second, locally maintained list that disagrees with it would
 * only ever be wrong. When the SSO authenticator is written it should not call
 * EmailDomainPolicy at all.
 *
 * Exact matches only: listing bidmc.harvard.edu does NOT admit
 * anything.bidmc.harvard.edu, and listing harvard.edu does not admit
 * bidmc.harvard.edu. Implicit subdomain matching is how an allowlist quietly
 * grows to include a department's mailing-list host or a student domain; if a
 * subdomain belongs here, somebody should have to type it.
 */
#[ORM\Entity(repositoryClass: \Pixiekat\HMFPSearchToolBundle\Repository\AllowedEmailDomainRepository::class)]
#[ORM\Table(name: 'allowed_email_domains')]
class AllowedEmailDomain {
  use PixieTraits\EntityIdTrait;

  /**
   * The domain, lower-cased, no leading '@'. Unique.
   *
   * Stored normalised so the lookup is a plain equality against an index,
   * rather than a LOWER() on every row.
   */
  #[ORM\Column(name: 'domain', type: 'string', length: 253, unique: true)]
  private string $domain;

  /**
   * Why it is on the list — "BIDMC main", "Beth Israel Lahey Health". Optional,
   * but the next administrator will want to know.
   */
  #[ORM\Column(name: 'note', type: 'string', length: 255, nullable: true)]
  private ?string $note = null;

  /**
   * SET NULL: removing an administrator must not remove the domains they added.
   */
  #[ORM\ManyToOne(targetEntity: User::class)]
  #[ORM\JoinColumn(name: 'added_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
  private ?User $addedBy = null;

  #[ORM\Column(name: 'added_at', type: 'datetime_immutable')]
  private \DateTimeImmutable $addedAt;

  public function __construct(string $domain, ?User $addedBy = null, ?string $note = null) {
    $normalised = self::normaliseDomain($domain);

    if ($normalised === null) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a usable email domain.', $domain));
    }

    $this->domain  = $normalised;
    $this->addedBy = $addedBy;
    $this->note    = $note === null || trim($note) === '' ? null : trim($note);
    $this->addedAt = new \DateTimeImmutable();
  }

  public function getDomain(): string {
    return $this->domain;
  }

  public function getNote(): ?string {
    return $this->note;
  }

  public function getAddedBy(): ?User {
    return $this->addedBy;
  }

  public function getAddedAt(): \DateTimeImmutable {
    return $this->addedAt;
  }

  /**
   * Turns what an administrator typed into a stored domain, or null if it
   * cannot be one.
   *
   * Forgiving about the things people paste — a leading '@', a whole address,
   * surrounding spaces, capitals, a trailing dot — and strict about the shape
   * of what is left: at least one dot, labels of letters, digits and hyphens.
   * Internationalised domains must be entered in their xn-- form; converting
   * them here would need ext-intl's idn_to_ascii and is a problem nobody here
   * has yet.
   *
   * Static and pure so it can be unit-tested without a database, and shared
   * with EmailDomainPolicy so "what domain is this address on?" has one answer.
   */
  public static function normaliseDomain(string $input): ?string {
    $domain = mb_strtolower(trim($input));

    // Accept a pasted address: keep what follows the LAST '@'. The last, not the
    // first, because a quoted local part may legally contain an '@' of its own.
    $at = strrpos($domain, '@');
    if ($at !== false) {
      $domain = substr($domain, $at + 1);
    }

    $domain = rtrim($domain, '.');

    if ($domain === '' || strlen($domain) > 253 || !str_contains($domain, '.')) {
      return null;
    }

    // One label: starts and ends with a letter or digit, hyphens allowed inside,
    // 63 characters at most. Repeated, dot-separated.
    $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    if (preg_match('/^' . $label . '(?:\.' . $label . ')+$/', $domain) !== 1) {
      return null;
    }

    return $domain;
  }
}
