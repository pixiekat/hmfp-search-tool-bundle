<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Pixiekat\HMFPSearchToolBundle\Enum\ClaimMethod;
use Pixiekat\HMFPSearchToolBundle\Enum\ClaimStatus;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;

/**
 * One account's assertion that it belongs to one physician.
 *
 * The link between a login and a provider record. Nothing infers it: the
 * physician extract carries no email address, and matching on names cannot work
 * — 10,933 distinct cred_ids in the sample share only 10,869 distinct first+last
 * pairs, so 59 name pairs belong to more than one person, among them three
 * Michael Murphys and three David Lees. A name match would hand one of them
 * write access to another's record about 0.6% of the time, and fuzzy matching
 * widens that rather than narrowing it.
 *
 * So the link is claimed, verified once, and then stored. Authorisation reads
 * the stored link and never re-derives it.
 */
#[ORM\Entity(repositoryClass: \Pixiekat\HMFPSearchToolBundle\Repository\PhysicianClaimRepository::class)]
#[ORM\Table(name: 'physician_claims')]
/**
 * The stewards' queue: open claims, oldest first. Same shape and same reasoning
 * as IDX_PHYSEDIT_QUEUE.
 */
#[ORM\Index(name: 'IDX_PHYSCLAIM_QUEUE', columns: ['status', 'claimed_at'])]
/**
 * The voter's access path: "does this user hold a granting claim?" — asked on
 * every permission check against a physician, so it must not be a table scan.
 */
#[ORM\Index(name: 'IDX_PHYSCLAIM_USER_STATUS', columns: ['user_id', 'status'])]
class PhysicianClaim {
  use PixieTraits\EntityIdTrait;

  /**
   * How long an emailed confirmation link stays usable.
   *
   * Two days: long enough to survive a weekend and a clinical rota, short
   * enough that an abandoned claim expires rather than sitting in a mailbox
   * indefinitely as a working credential.
   */
  public const TOKEN_TTL = 'P2D';

  /**
   * How many wrong NPIs a single claim tolerates before it stops accepting any.
   *
   * Not really a brute-force control — the NPI is public, so there is nothing to
   * brute force. It is a tripwire. A claim that has been handed forty different
   * NPIs is somebody working through a list, and the value of this column is
   * that the attempt becomes visible instead of merely failing quietly.
   */
  public const MAX_ATTEMPTS = 5;

  /**
   * The physician being claimed.
   *
   * CASCADE: a claim on a record that no longer exists grants nothing to nobody,
   * and would only be a row every query has to exclude.
   */
  #[ORM\ManyToOne(targetEntity: Physician::class)]
  #[ORM\JoinColumn(name: 'physician_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
  private Physician $physician;

  /**
   * The account doing the claiming.
   *
   * CASCADE here, unlike PhysicianEdit::$editedBy which is SET NULL. The
   * difference is what the row is FOR: an edit is a historical fact that has to
   * outlive its author, whereas a claim is a live grant of permission, and a
   * grant to a deleted account is not history worth keeping — it is a dangling
   * permission. The audit log keeps the narrative; this table keeps only claims
   * that still mean something.
   */
  #[ORM\ManyToOne(targetEntity: User::class)]
  #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
  private User $user;

  /**
   * Mirrors $physician's id while this claim grants editing, and is NULL
   * otherwise. Carries a UNIQUE index.
   *
   * This is how "one physician can be claimed by at most one account" becomes a
   * database guarantee rather than a check somebody has to remember. MySQL and
   * MariaDB permit any number of NULLs in a unique index, so:
   *
   *   - Pending, Rejected and Revoked claims hold NULL and never collide. Any
   *     number of people may have an open or a refused claim on one physician.
   *   - Verified and Approved claims hold the physician's id, so the SECOND one
   *     to be written is rejected by the engine.
   *
   * Doing it this way matters because the alternative — SELECT then INSERT — has
   * a race between the two statements, and the race is not hypothetical: the
   * contested case is two people with the same name, who will plausibly be
   * prompted to claim by the same email on the same morning.
   *
   * The rejection is not an error to swallow. It is the most informative event
   * this flow can produce, and it fires precisely on the 59 colliding name
   * pairs, so the controller turns it into a contested-claim notice for a
   * steward. The worst case becomes the detector.
   *
   * Mapped as a plain integer rather than a second relation to Physician: it is
   * a constraint-enforcement mirror, not a second association, and mapping it as
   * one would invite somebody to traverse it as though it meant something
   * different from $physician.
   */
  #[ORM\Column(name: 'active_claim_for', type: 'integer', nullable: true, unique: true)]
  private ?int $activeClaimFor = null;

  #[ORM\Column(name: 'status', type: 'string', length: 32, enumType: ClaimStatus::class)]
  private ClaimStatus $status = ClaimStatus::Pending;

  /**
   * How the claim was established. Null until it is.
   */
  #[ORM\Column(name: 'method', type: 'string', length: 32, nullable: true, enumType: ClaimMethod::class)]
  private ?ClaimMethod $method = null;

  /**
   * SHA-256 of the emailed token, hex encoded. Never the token itself.
   *
   * Same discipline as a password reset, and for the same reason: a confirmation
   * link is a bearer credential for as long as it is valid, so a leaked database
   * — or a stray query in a log, or a screenshot of a support tool — must not
   * hand somebody a working link. Hashing means the only copy of the real token
   * is the one in the claimant's inbox.
   *
   * Unique, so a token can never be ambiguous between two claims. Nullable,
   * because it is cleared the moment it is spent.
   */
  #[ORM\Column(name: 'token_hash', type: 'string', length: 64, nullable: true, unique: true)]
  private ?string $tokenHash = null;

  #[ORM\Column(name: 'token_expires_at', type: 'datetime_immutable', nullable: true)]
  private ?\DateTimeImmutable $tokenExpiresAt = null;

  /**
   * Failed NPI attempts against this claim. See self::MAX_ATTEMPTS.
   */
  #[ORM\Column(name: 'attempts', type: 'integer', options: ['default' => 0])]
  private int $attempts = 0;

  /**
   * Who claimed, as they were named at the time. Never null.
   *
   * The same actor/actorLabel pairing as PhysicianEdit::$editedByLabel: the
   * foreign key answers "who is this now?", the snapshot answers "who was this
   * then?" — and since $user CASCADEs on delete, this snapshot is what makes the
   * audit log entry still readable afterwards.
   */
  #[ORM\Column(name: 'claimant_label', type: 'string', length: 255)]
  private string $claimantLabel;

  /**
   * Free text from whoever decided: why it was approved, refused or withdrawn.
   *
   * Worth having because the interesting claims are the ones that were refused,
   * and "refused" without a reason tells the next steward to look at the same
   * evidence and reach the same uncertainty again.
   */
  #[ORM\Column(name: 'note', type: 'text', nullable: true)]
  private ?string $note = null;

  #[ORM\Column(name: 'claimed_at', type: 'datetime_immutable')]
  private \DateTimeImmutable $claimedAt;

  #[ORM\Column(name: 'verified_at', type: 'datetime_immutable', nullable: true)]
  private ?\DateTimeImmutable $verifiedAt = null;

  /**
   * The steward who decided. Null while nobody has.
   *
   * SET NULL, matching PhysicianEdit::$reviewedBy: losing the decider's name is
   * regrettable, losing the record that a decision happened would be a hole.
   */
  #[ORM\ManyToOne(targetEntity: User::class)]
  #[ORM\JoinColumn(name: 'decided_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
  private ?User $decidedBy = null;

  #[ORM\Column(name: 'decided_at', type: 'datetime_immutable', nullable: true)]
  private ?\DateTimeImmutable $decidedAt = null;

  public function __construct(
    Physician $physician,
    User $user,
    ?string $claimantLabel = null,
    ?\DateTimeImmutable $claimedAt = null,
  ) {
    $label = $claimantLabel ?? $user->getUserIdentifier();

    if (trim($label) === '') {
      // Refused rather than defaulted, for the reason PhysicianEdit gives: an
      // anonymous row in an audit trail looks like a record while answering none
      // of the questions the record exists for.
      throw new \InvalidArgumentException('A claim must name its claimant.');
    }

    $this->physician     = $physician;
    $this->user          = $user;
    $this->claimantLabel = $label;
    $this->claimedAt     = $claimedAt ?? new \DateTimeImmutable();
  }

  public function getPhysician(): Physician {
    return $this->physician;
  }

  public function getUser(): User {
    return $this->user;
  }

  public function getStatus(): ClaimStatus {
    return $this->status;
  }

  public function getMethod(): ?ClaimMethod {
    return $this->method;
  }

  public function getAttempts(): int {
    return $this->attempts;
  }

  public function getClaimantLabel(): string {
    return $this->claimantLabel;
  }

  public function getNote(): ?string {
    return $this->note;
  }

  public function getClaimedAt(): \DateTimeImmutable {
    return $this->claimedAt;
  }

  public function getVerifiedAt(): ?\DateTimeImmutable {
    return $this->verifiedAt;
  }

  public function getDecidedBy(): ?User {
    return $this->decidedBy;
  }

  public function getDecidedAt(): ?\DateTimeImmutable {
    return $this->decidedAt;
  }

  public function getTokenExpiresAt(): ?\DateTimeImmutable {
    return $this->tokenExpiresAt;
  }

  /**
   * Whether this claim currently lets the claimant propose edits.
   *
   * Delegates to the enum rather than comparing cases here, so the policy has
   * exactly one home. @see ClaimStatus::grantsEditing()
   */
  public function grantsEditing(): bool {
    return $this->status->grantsEditing();
  }

  /**
   * Issues a fresh confirmation token and returns the PLAINTEXT, once.
   *
   * The plaintext is returned and never stored — the caller puts it straight
   * into the email and drops it. Asking for it a second time is impossible by
   * construction, which is the property that makes the hashed column worth
   * having.
   *
   * 32 random bytes from random_bytes(), which is cryptographically secure;
   * bin2hex makes it URL-safe without escaping. 256 bits of entropy is far past
   * anything guessable, which is why there is no rate limit on the link itself —
   * only on the NPI step, where the secret is public and the limit is a tripwire
   * rather than a defence.
   */
  public function issueToken(?\DateTimeImmutable $now = null): string {
    if ($this->status !== ClaimStatus::Pending) {
      // A token is only ever a way to move out of Pending. Minting one for a
      // claim that has already been decided would quietly re-open it.
      throw new \LogicException(sprintf(
        'Cannot issue a confirmation token for a claim that is %s.',
        $this->status->value,
      ));
    }

    $now   = $now ?? new \DateTimeImmutable();
    $token = bin2hex(random_bytes(32));

    $this->tokenHash      = hash('sha256', $token);
    $this->tokenExpiresAt = $now->add(new \DateInterval(self::TOKEN_TTL));

    return $token;
  }

  /**
   * Whether $token is this claim's live confirmation token.
   *
   * hash_equals rather than === because this comparison is against a secret, and
   * === short-circuits on the first differing byte. The NPI check below does not
   * need the same care — that value is published by CMS — but a token does.
   */
  public function matchesToken(string $token, ?\DateTimeImmutable $now = null): bool {
    if ($this->tokenHash === null || $this->tokenExpiresAt === null) {
      return false;
    }

    if (($now ?? new \DateTimeImmutable()) > $this->tokenExpiresAt) {
      return false;
    }

    return hash_equals($this->tokenHash, hash('sha256', $token));
  }

  /**
   * Whether this claim will still consider an NPI attempt.
   */
  public function acceptsAttempts(): bool {
    return $this->status === ClaimStatus::Pending && $this->attempts < self::MAX_ATTEMPTS;
  }

  /**
   * Records a failed NPI attempt.
   */
  public function recordFailedAttempt(): self {
    $this->attempts++;

    return $this;
  }

  /**
   * Whether $candidate is the claimed physician's NPI.
   *
   * Digits only on both sides: an NPI is ten digits, and somebody reading one
   * off a badge or a letter may well type spaces or dashes into the box. A
   * formatting difference is not a wrong answer, and treating it as one would
   * burn an attempt on a correct claimant.
   *
   * Returns false when the physician has no NPI at all. The column is nullable,
   * and although the current extract fills it for all 10,933 rows, it belongs to
   * the export team and a future partial file could arrive with gaps. If that
   * happens the check must fail closed — an empty candidate matching an empty
   * NPI would let anybody claim every incomplete record in one pass.
   */
  public function matchesNpi(string $candidate): bool {
    $expected = preg_replace('/\D+/', '', (string) $this->physician->getNpi());
    $supplied = preg_replace('/\D+/', '', $candidate);

    if ($expected === '' || $supplied === '') {
      return false;
    }

    return $expected === $supplied;
  }

  /**
   * Marks the claim verified and spends the token.
   *
   * The exclusivity slot is taken here, so the UNIQUE index decides whether this
   * claim or a competing one wins — see $activeClaimFor. That makes this the
   * method whose flush() can throw UniqueConstraintViolationException, which the
   * caller must catch and present as a contested claim rather than a failure.
   */
  public function verify(ClaimMethod $method, ?\DateTimeImmutable $now = null, ?string $note = null): self {
    if ($this->status !== ClaimStatus::Pending) {
      throw new \LogicException(sprintf('Cannot verify a claim that is %s.', $this->status->value));
    }

    $this->status     = ClaimStatus::Verified;
    $this->method     = $method;
    $this->verifiedAt = $now ?? new \DateTimeImmutable();

    if ($note !== null) {
      $this->note = $note;
    }

    // Spent. A confirmation link must work exactly once; leaving it live would
    // make the email a standing credential for the rest of its TTL.
    $this->tokenHash      = null;
    $this->tokenExpiresAt = null;

    $this->syncExclusivity();

    return $this;
  }

  /**
   * A steward vouches for the claim.
   *
   * Reachable from Pending as well as Verified: a steward who recognises the
   * claimant should not have to send them off to find their NPI first.
   */
  public function approve(User $steward, ?string $note = null, ?\DateTimeImmutable $now = null): self {
    if ($this->status->isFinal()) {
      throw new \LogicException(sprintf('Cannot approve a claim that is %s.', $this->status->value));
    }

    $now = $now ?? new \DateTimeImmutable();

    $this->status    = ClaimStatus::Approved;
    $this->method    = $this->method ?? ClaimMethod::StewardManual;
    $this->note      = $note ?? $this->note;
    $this->decidedBy = $steward;
    $this->decidedAt = $now;

    // A claim approved straight from Pending was never verified, but it IS now
    // live, and "when did this start granting access?" needs an answer either
    // way.
    $this->verifiedAt = $this->verifiedAt ?? $now;

    $this->tokenHash      = null;
    $this->tokenExpiresAt = null;

    $this->syncExclusivity();

    return $this;
  }

  /**
   * Refuses a claim that never went live.
   */
  public function reject(User $steward, ?string $note = null, ?\DateTimeImmutable $now = null): self {
    $this->status    = ClaimStatus::Rejected;
    $this->note      = $note ?? $this->note;
    $this->decidedBy = $steward;
    $this->decidedAt = $now ?? new \DateTimeImmutable();

    $this->tokenHash      = null;
    $this->tokenExpiresAt = null;

    $this->syncExclusivity();

    return $this;
  }

  /**
   * Withdraws a claim that was live.
   *
   * Frees the physician's slot, so the right person can claim afterwards. Note
   * what this does NOT do: anything the claimant edited stays published. Undoing
   * their edits is PhysicianEditManager::revert()'s job, and keeping the two
   * separate is deliberate — a claim withdrawn because somebody changed roles
   * should not silently roll back a year of accurate bios.
   */
  public function revoke(User $steward, ?string $note = null, ?\DateTimeImmutable $now = null): self {
    if (!$this->status->grantsEditing()) {
      throw new \LogicException(sprintf('Cannot revoke a claim that is %s.', $this->status->value));
    }

    $this->status    = ClaimStatus::Revoked;
    $this->note      = $note ?? $this->note;
    $this->decidedBy = $steward;
    $this->decidedAt = $now ?? new \DateTimeImmutable();

    $this->syncExclusivity();

    return $this;
  }

  /**
   * Keeps $activeClaimFor in step with $status.
   *
   * Every transition funnels through here rather than setting the column itself,
   * because the UNIQUE index is only a guarantee for as long as the mirror is
   * accurate. One place to get right, and it is derived from the status rather
   * than tracked alongside it, so the two cannot disagree.
   */
  private function syncExclusivity(): void {
    $this->activeClaimFor = $this->status->grantsEditing()
      ? $this->physician->getId()
      : null;
  }
}
