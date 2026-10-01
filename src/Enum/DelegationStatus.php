<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Enum;

/**
 * Where a delegation — "this person may edit my profile on my behalf" — sits.
 *
 * Modelled on ClaimStatus rather than EditReviewStatus, for ClaimStatus's reason:
 * a delegation hands somebody standing to speak for a physician, so it grants
 * nothing until the person on the receiving end has said yes. Pending is inert.
 *
 * Note what is NOT a status here: "expired". An unanswered invitation whose link
 * has run out is still Pending — it simply has a dead token, and the claimant can
 * send a fresh one. Expiry is derived from token_expires_at the same way the
 * claim flow derives it, so there is never a cron job whose absence leaves rows
 * claiming to be something they are not.
 *
 * And note what a status CANNOT promise on its own: an Accepted delegation grants
 * editing only while the claim it hangs off is live. That second condition is
 * enforced by the query the voter runs — see
 * PhysicianDelegationRepository::delegatedPhysicianIdsFor() — not by this enum,
 * because the enum cannot see the claim.
 */
enum DelegationStatus: string {

  /**
   * Invited, and waiting on the invitee.
   *
   * The email has gone out and nothing has come back. Grants NOTHING — so an
   * invitation typed to the wrong address costs one email, not a permission.
   */
  case Pending = 'pending';

  /**
   * The invitee followed the link and said yes.
   *
   * The only status that can grant editing, and only while the claim is live.
   */
  case Accepted = 'accepted';

  /**
   * The invitee said no.
   *
   * Kept rather than deleted: "we asked, and they declined" is what stops the
   * claimant — or a steward — from wondering whether the email ever arrived.
   */
  case Declined = 'declined';

  /**
   * Withdrawn, by the claimant or by a steward, or because the claim itself was.
   *
   * Reachable from Pending (an invitation taken back before it was answered) and
   * from Accepted (a delegate removed). Distinct from Declined on purpose: one is
   * the invitee's decision, the other is somebody else's, and "who ended this?"
   * is the first question anybody asks about an ended grant.
   */
  case Revoked = 'revoked';

  public function label(): string {
    return match ($this) {
      self::Pending  => 'Invitation sent',
      self::Accepted => 'Active delegate',
      self::Declined => 'Declined',
      self::Revoked  => 'Removed',
    };
  }

  /**
   * Whether a delegation in this status lets the delegate propose edits —
   * subject to the claim being live, which this enum cannot see.
   *
   * The policy switch, in one place, for the same reason as
   * ClaimStatus::grantsEditing().
   */
  public function grantsEditing(): bool {
    return $this === self::Accepted;
  }

  /**
   * Whether this delegation still occupies its (claim, delegate) slot.
   *
   * Drives the UNIQUE index on physician_delegations: at most ONE open
   * delegation per person per claim. Pending is included so a second click on
   * "Invite" re-sends the first invitation rather than stacking up rows, and
   * Accepted is included so an existing delegate cannot be invited twice.
   */
  public function isOpen(): bool {
    return $this === self::Pending || $this === self::Accepted;
  }

  /**
   * Whether this delegation is finished with, either way.
   */
  public function isFinal(): bool {
    return !$this->isOpen();
  }

  /**
   * Per-case predicates, for templates — see the note on ClaimStatus's.
   * Prefer the question methods above where one fits.
   */
  public function isPending(): bool {
    return $this === self::Pending;
  }

  public function isAccepted(): bool {
    return $this === self::Accepted;
  }

  public function isDeclined(): bool {
    return $this === self::Declined;
  }

  public function isRevoked(): bool {
    return $this === self::Revoked;
  }
}
