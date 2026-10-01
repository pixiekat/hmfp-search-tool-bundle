<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Pixiekat\HMFPSearchToolBundle\Enum\DelegationStatus;
use Pixiekat\SymfonyHelpers\Traits\Entity as PixieTraits;

/**
 * One account allowed to propose edits to a physician's profile on the
 * claimant's behalf — an assistant, a practice manager, a colleague.
 *
 * ── Why it hangs off the CLAIM and not the physician ───────────────────────
 * A delegation is the claimant lending out their own standing. It cannot be
 * worth more than the thing it was borrowed from, so it points at the claim, and
 * the voter's query only honours it while that claim grantsEditing(). The
 * consequence is the whole point: revoking a claim cuts off every delegate in
 * the same instant, with no second write for anyone to forget. And because a
 * revoked claim is final and a later claim is a NEW row, a delegation can never
 * come back to life under somebody else's claim.
 *
 * ── The invitee always has a user row ──────────────────────────────────────
 * If the invited address has no account, one is created on the spot — inactive,
 * with no password — and $activatesAccount records that an invitation made it.
 * That flag is what lets the emailed link ACTIVATE the account (choose a
 * password), and it is deliberately narrow: an account that existed for any
 * other reason is never activated by an invitation, so an administrator's
 * "deactivate" cannot be undone by somebody sending that person an invite.
 *
 * Status transitions, all funnelled through methods below:
 *
 *   Pending ──accept()──▶ Accepted ──revoke()──▶ Revoked
 *      │                                            ▲
 *      ├──decline()──▶ Declined                     │
 *      └──revoke()──────────────────────────────────┘
 */
#[ORM\Entity(repositoryClass: \Pixiekat\HMFPSearchToolBundle\Repository\PhysicianDelegationRepository::class)]
#[ORM\Table(name: 'physician_delegations')]
/**
 * The voter's access path: "which physicians is this user a delegate for?" —
 * asked once per request, see PhysicianVoter::holdsClaimOn().
 */
#[ORM\Index(name: 'IDX_PHYSDELEG_DELEGATE_STATUS', columns: ['delegate_id', 'status'])]
/**
 * At most one OPEN delegation per person per claim. See $openSlot.
 */
#[ORM\UniqueConstraint(name: 'UNIQ_PHYSDELEG_OPEN', columns: ['claim_id', 'delegate_id', 'open_slot'])]
class PhysicianDelegation {
  use PixieTraits\EntityIdTrait;

  /**
   * How long an emailed invitation link stays usable.
   *
   * Seven days rather than the claim flow's two. A claim link goes to the person
   * who just clicked "claim" and is waiting for it; an invitation lands on
   * somebody who did not ask for it and may be on leave, on nights, or simply
   * slow to trust an unexpected email — which is exactly the caution we want
   * from them. A week covers a rota; beyond that the claimant re-sends.
   */
  public const TOKEN_TTL = 'P7D';

  /**
   * How many open delegations one claim may have at once.
   *
   * A tripwire rather than a business rule, in the same spirit as
   * PhysicianClaim::MAX_ATTEMPTS. A physician needs an assistant or two; a
   * claim with forty delegates is somebody using the invite form as a mailing
   * list, and the value of the limit is that it makes that visible.
   */
  public const MAX_OPEN_PER_CLAIM = 10;

  /**
   * The claim whose standing is being lent out.
   *
   * CASCADE: if the claim row itself is ever deleted there is nothing left for
   * this to borrow from. (Claims are normally revoked, not deleted — that path
   * leaves this row in place, readable, and granting nothing.)
   */
  #[ORM\ManyToOne(targetEntity: PhysicianClaim::class)]
  #[ORM\JoinColumn(name: 'claim_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
  private PhysicianClaim $claim;

  /**
   * The account being given access.
   *
   * CASCADE, matching PhysicianClaim::$user, and for its reason: this is a live
   * grant, and a grant to a deleted account is a dangling permission rather than
   * history. $delegateLabel and the audit log keep the narrative.
   */
  #[ORM\ManyToOne(targetEntity: User::class)]
  #[ORM\JoinColumn(name: 'delegate_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
  private User $delegate;

  /**
   * Who sent the invitation. Normally the claimant.
   *
   * SET NULL: an invitation is a historical fact that outlives its sender, the
   * same reasoning as PhysicianEdit::$editedBy.
   */
  #[ORM\ManyToOne(targetEntity: User::class)]
  #[ORM\JoinColumn(name: 'invited_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
  private ?User $invitedBy;

  #[ORM\Column(name: 'status', type: 'string', length: 32, enumType: DelegationStatus::class)]
  private DelegationStatus $status = DelegationStatus::Pending;

  /**
   * 1 while this delegation is open (Pending or Accepted), NULL once it is not.
   * Part of UNIQ_PHYSDELEG_OPEN (claim_id, delegate_id, open_slot).
   *
   * The same trick as PhysicianClaim::$activeClaimFor, in composite form.
   * MariaDB and MySQL treat a unique-index row containing ANY NULL as distinct
   * from every other row, so:
   *
   *   - finished delegations hold NULL and never collide — somebody can be
   *     invited, decline, and be invited again, leaving a readable history;
   *   - open ones hold 1, so a SECOND open delegation for the same person on
   *     the same claim is refused by the engine rather than by a
   *     SELECT-then-INSERT check with a race between the two.
   *
   * Nothing reads this column. It exists to be indexed.
   */
  #[ORM\Column(name: 'open_slot', type: 'boolean', nullable: true)]
  private ?bool $openSlot = true;

  /**
   * Whether this invitation may activate the delegate's account.
   *
   * True when the account was created BY AN INVITATION — this one, or an
   * earlier one from another physician that the person has not answered yet —
   * and had never been activated when this invitation was sent. The second case
   * is why this is not simply "this row created the account": somebody invited
   * by two physicians in the same week must be able to set themselves up from
   * whichever email they open first. DelegationManager::invite() decides it.
   *
   * The only thing that permits the emailed link to set a password. See the
   * class docblock, and canActivateAccount().
   */
  #[ORM\Column(name: 'activates_account', type: 'boolean', options: ['default' => false])]
  private bool $activatesAccount = false;

  /**
   * SHA-256 of the emailed token, hex encoded. Never the token itself — see
   * PhysicianClaim::$tokenHash for why. Cleared the moment the invitation is
   * answered or withdrawn.
   *
   * Matters MORE here than on a claim: for a newly created account this link is
   * how its first password gets chosen, so a working token is as good as the
   * account until it is spent.
   */
  #[ORM\Column(name: 'token_hash', type: 'string', length: 64, nullable: true, unique: true)]
  private ?string $tokenHash = null;

  #[ORM\Column(name: 'token_expires_at', type: 'datetime_immutable', nullable: true)]
  private ?\DateTimeImmutable $tokenExpiresAt = null;

  /**
   * The delegate's address as it was when invited. The claimant's the same.
   *
   * Snapshots, for the reason PhysicianClaim::$claimantLabel gives: the foreign
   * keys answer "who is this now?", these answer "who was this then?" — and
   * they are what keeps the history readable after a CASCADE.
   */
  #[ORM\Column(name: 'delegate_label', type: 'string', length: 255)]
  private string $delegateLabel;

  #[ORM\Column(name: 'inviter_label', type: 'string', length: 255)]
  private string $inviterLabel;

  /**
   * Why it ended, when it did. Free text; the steward screens show it.
   */
  #[ORM\Column(name: 'note', type: 'text', nullable: true)]
  private ?string $note = null;

  #[ORM\Column(name: 'invited_at', type: 'datetime_immutable')]
  private \DateTimeImmutable $invitedAt;

  /**
   * When the delegate said yes. Survives a later revoke(), deliberately:
   * "was this person EVER able to edit?" is how revoke-and-revert decides whose
   * edits to roll back, and a revoked delegate's edits are exactly the ones
   * that question is about.
   */
  #[ORM\Column(name: 'accepted_at', type: 'datetime_immutable', nullable: true)]
  private ?\DateTimeImmutable $acceptedAt = null;

  /**
   * When it ended — declined or revoked. Null while open.
   */
  #[ORM\Column(name: 'ended_at', type: 'datetime_immutable', nullable: true)]
  private ?\DateTimeImmutable $endedAt = null;

  /**
   * Who ended it, when that was not the delegate declining. SET NULL, as
   * PhysicianClaim::$decidedBy.
   */
  #[ORM\ManyToOne(targetEntity: User::class)]
  #[ORM\JoinColumn(name: 'ended_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
  private ?User $endedBy = null;

  public function __construct(
    PhysicianClaim $claim,
    User $delegate,
    User $invitedBy,
    bool $activatesAccount = false,
    ?\DateTimeImmutable $invitedAt = null,
  ) {
    if (!$claim->grantsEditing()) {
      // Lending out standing you do not have. The controller never offers this,
      // but a delegation minted against a pending or revoked claim would sit in
      // the table looking legitimate until the day that claim went live.
      throw new \LogicException(sprintf(
        'Cannot delegate from a claim that is %s.',
        $claim->getStatus()->value,
      ));
    }

    if ($delegate === $claim->getUser()
      || ($delegate->getId() !== null && $delegate->getId() === $claim->getUser()->getId())) {
      // Checked by identity AND id: a freshly constructed, unsaved user has no
      // id yet, and two unsaved objects both returning null must not compare
      // equal by accident.
      throw new \InvalidArgumentException('A claimant cannot delegate to themselves.');
    }

    $this->claim          = $claim;
    $this->delegate       = $delegate;
    $this->invitedBy      = $invitedBy;
    $this->activatesAccount = $activatesAccount;
    $this->delegateLabel  = $delegate->getUserIdentifier();
    $this->inviterLabel   = $invitedBy->getUserIdentifier();
    $this->invitedAt      = $invitedAt ?? new \DateTimeImmutable();
  }

  public function getClaim(): PhysicianClaim {
    return $this->claim;
  }

  /**
   * Shortcut for templates and emails, which almost always want this.
   */
  public function getPhysician(): Physician {
    return $this->claim->getPhysician();
  }

  public function getDelegate(): User {
    return $this->delegate;
  }

  public function getInvitedBy(): ?User {
    return $this->invitedBy;
  }

  public function getStatus(): DelegationStatus {
    return $this->status;
  }

  public function activatesAccount(): bool {
    return $this->activatesAccount;
  }

  public function getDelegateLabel(): string {
    return $this->delegateLabel;
  }

  public function getInviterLabel(): string {
    return $this->inviterLabel;
  }

  public function getNote(): ?string {
    return $this->note;
  }

  public function getInvitedAt(): \DateTimeImmutable {
    return $this->invitedAt;
  }

  public function getAcceptedAt(): ?\DateTimeImmutable {
    return $this->acceptedAt;
  }

  public function getEndedAt(): ?\DateTimeImmutable {
    return $this->endedAt;
  }

  public function getEndedBy(): ?User {
    return $this->endedBy;
  }

  public function getTokenExpiresAt(): ?\DateTimeImmutable {
    return $this->tokenExpiresAt;
  }

  /**
   * Whether this delegation lets the delegate propose edits right now.
   *
   * Both halves: the delegate said yes, AND the claimant still holds the
   * claim. The voter asks the database the same question in one query; this is
   * the in-memory version for templates and tests.
   */
  public function grantsEditing(): bool {
    return $this->status->grantsEditing() && $this->claim->grantsEditing();
  }

  /**
   * Issues a fresh invitation token and returns the PLAINTEXT, once.
   *
   * Same construction and same reasoning as PhysicianClaim::issueToken(). Calling
   * it again replaces the hash, so re-sending an invitation kills the old link —
   * one live link at a time.
   */
  public function issueToken(?\DateTimeImmutable $now = null): string {
    if ($this->status !== DelegationStatus::Pending) {
      throw new \LogicException(sprintf(
        'Cannot issue an invitation token for a delegation that is %s.',
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
   * Whether $token is this delegation's live invitation token.
   *
   * hash_equals, for the reason PhysicianClaim::matchesToken() gives.
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
   * Whether following this invitation may set the delegate's first password.
   *
   * All three conditions, and each one closes a specific hole:
   *
   *   - activatesAccount: an invitation MADE the account. Without this, inviting
   *     an existing user would let the link reset their password.
   *   - inactive: the account has never been switched on. Once it has, it has a
   *     login of its own and the link has no business touching it.
   *   - no password: belt and braces for the above — an account somebody has
   *     already chosen a password for is not "waiting to be activated", however
   *     its active flag reads.
   *
   * ── When Entra SSO arrives ────────────────────────────────────────────────
   * This is the question whose ANSWER changes, not whose meaning does. With SSO
   * a new invitee's "first login" is signing in with Microsoft, not choosing a
   * password here — see DelegationManager::activateAccount() for where that
   * branch goes.
   */
  public function canActivateAccount(): bool {
    return $this->activatesAccount
      && $this->delegate->isInactive()
      && $this->delegate->getPassword() === null;
  }

  /**
   * The delegate says yes.
   */
  public function accept(?\DateTimeImmutable $now = null): self {
    if ($this->status !== DelegationStatus::Pending) {
      throw new \LogicException(sprintf('Cannot accept a delegation that is %s.', $this->status->value));
    }

    $this->status     = DelegationStatus::Accepted;
    $this->acceptedAt = $now ?? new \DateTimeImmutable();

    // Spent: an invitation link must work exactly once.
    $this->spendToken();
    $this->syncOpenSlot();

    return $this;
  }

  /**
   * The delegate says no.
   *
   * Only from Pending. Somebody who accepted and has changed their mind is
   * removed by the claimant or a steward — a delegate quietly "declining"
   * after the fact would leave the claimant believing they still had help.
   */
  public function decline(?string $note = null, ?\DateTimeImmutable $now = null): self {
    if ($this->status !== DelegationStatus::Pending) {
      throw new \LogicException(sprintf('Cannot decline a delegation that is %s.', $this->status->value));
    }

    $this->status  = DelegationStatus::Declined;
    $this->note    = $note ?? $this->note;
    $this->endedAt = $now ?? new \DateTimeImmutable();

    $this->spendToken();
    $this->syncOpenSlot();

    return $this;
  }

  /**
   * The claimant, a steward, or a revoked claim ends the delegation.
   *
   * From Pending (an invitation withdrawn) or Accepted (a delegate removed).
   * $by is null when the SYSTEM ended it — see
   * DelegationManager::endAllForClaim() — rather than a person.
   */
  public function revoke(?User $by, ?string $note = null, ?\DateTimeImmutable $now = null): self {
    if (!$this->status->isOpen()) {
      throw new \LogicException(sprintf('Cannot remove a delegation that is %s.', $this->status->value));
    }

    $this->status  = DelegationStatus::Revoked;
    $this->note    = $note ?? $this->note;
    $this->endedBy = $by;
    $this->endedAt = $now ?? new \DateTimeImmutable();

    $this->spendToken();
    $this->syncOpenSlot();

    return $this;
  }

  private function spendToken(): void {
    $this->tokenHash      = null;
    $this->tokenExpiresAt = null;
  }

  /**
   * Keeps $openSlot in step with $status — the only place it is written, so
   * the UNIQUE index stays a guarantee. See PhysicianClaim::syncExclusivity().
   */
  private function syncOpenSlot(): void {
    $this->openSlot = $this->status->isOpen() ? true : null;
  }
}
