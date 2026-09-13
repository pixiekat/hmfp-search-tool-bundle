<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Enum;

/**
 * Where a physician's claim on their own record sits.
 *
 * Deliberately NOT modelled on EditReviewStatus. An edit is published first and
 * reviewed afterwards, because a wrong bio is cheap to revert. A claim is the
 * opposite: it hands someone standing to speak as another person, so it grants
 * nothing until it has cleared a bar. Pending is therefore inert, where
 * EditReviewStatus::Unreviewed is already live.
 */
enum ClaimStatus: string {

  /**
   * Started, and waiting on the claimant.
   *
   * The confirmation email has been sent and nothing has come back. Grants
   * NOTHING — which is what makes it safe for two people to hold a pending
   * claim on the same physician at once. Only verification is exclusive.
   */
  case Pending = 'pending';

  /**
   * The claimant cleared the self-service bar: they followed the link sent to
   * the address on their account, and supplied the matching NPI.
   *
   * Whether this is enough to edit is a policy question, answered in exactly
   * one place — self::grantsEditing(). Bear in mind what this status actually
   * proves: that someone with access to that mailbox can look up a public NPI.
   * It is evidence, not proof.
   */
  case Verified = 'verified';

  /**
   * A steward vouched for the claim by hand.
   *
   * The strongest status available, and the only one backed by a human who
   * knows these people. Reachable directly from Pending — a steward who
   * recognises the claimant does not need them to go and find their NPI.
   */
  case Approved = 'approved';

  /**
   * Refused, before it ever granted anything.
   *
   * Kept rather than deleted, for the same reason PhysicianEdit keeps rejected
   * rows: "somebody tried to claim this record and was turned down" is the
   * single most interesting thing this table can record.
   */
  case Rejected = 'rejected';

  /**
   * Was live, and has been taken away.
   *
   * Distinct from Rejected on purpose. Rejected means the claim was never good;
   * Revoked means it was acted on and then withdrawn — so anything that account
   * edited while it held the claim is worth a second look. Collapsing the two
   * would lose exactly the signal an investigation starts from.
   */
  case Revoked = 'revoked';

  public function label(): string {
    return match ($this) {
      self::Pending  => 'Awaiting confirmation',
      self::Verified => 'Verified by email and NPI',
      self::Approved => 'Approved by a data steward',
      self::Rejected => 'Refused',
      self::Revoked  => 'Withdrawn',
    };
  }

  /**
   * Whether a claim in this status lets the claimant propose edits.
   *
   * THE policy switch for this whole feature, and the reason it is a method on
   * an enum rather than an `in_array` in the voter: when someone decides that
   * email-plus-NPI is not enough and only a steward may grant editing, the
   * change is dropping self::Verified from this list. One line, one place, and
   * every caller — voter, controller, template — follows automatically.
   */
  public function grantsEditing(): bool {
    return $this === self::Verified || $this === self::Approved;
  }

  /**
   * Whether this claim still needs somebody to do something.
   *
   * Drives the stewards' queue. Verified is included even though it already
   * grants editing: a self-service claim should still cross a human's desk,
   * it just is not made to wait there.
   */
  public function isOpen(): bool {
    return $this === self::Pending || $this === self::Verified;
  }

  /**
   * Whether this claim is finished with, either way.
   */
  public function isFinal(): bool {
    return $this === self::Rejected || $this === self::Revoked;
  }

  /**
   * Per-case predicates, for templates.
   *
   * Twig cannot compare enum cases without constant() and a fully qualified class
   * name, which turns a simple condition into an unreadable line and puts the
   * class path into markup where a rename will not find it. These let a template
   * say `claim.status.isRevoked` instead.
   *
   * Prefer the QUESTION methods above where one fits — grantsEditing(), isOpen()
   * and isFinal() say what the code cares about, where naming a case says only
   * which case it is. A button gated on "not revoked" still appears on a pending
   * claim that cannot be revoked either; one gated on grantsEditing() does not.
   */
  public function isPending(): bool {
    return $this === self::Pending;
  }

  public function isVerified(): bool {
    return $this === self::Verified;
  }

  public function isApproved(): bool {
    return $this === self::Approved;
  }

  /**
   * Whether this claim is rejected.
   */
  public function isRejected(): bool {
    return $this === self::Rejected;
  }

  /**
   * Whether this claim is revoked.
   */
  public function isRevoked(): bool {
    return $this === self::Revoked;
  }

  /**
   * The statuses that occupy a physician's one exclusive claim slot.
   *
   * @return list<string>
   */
  public static function grantingValues(): array {
    return array_values(array_map(
      static fn (self $case): string => $case->value,
      array_filter(self::cases(), static fn (self $case): bool => $case->grantsEditing()),
    ));
  }
}
