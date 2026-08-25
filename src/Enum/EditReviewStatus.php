<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Enum;

/**
 * Where a proposed edit sits in review.
 *
 * ── Only one status makes an edit visible ──────────────────────────────────
 * `Live` is the sole status the resolver looks at. Everything else — pending,
 * rejected, superseded — is history. That single rule is what keeps the read
 * path cheap and unambiguous: "latest Live edit, else the imported value",
 * with no precedence table to reason about.
 */
enum EditReviewStatus: string {

  /**
   * Proposed, awaiting an administrator. Invisible to the public.
   */
  case Pending = 'pending';

  /**
   * Approved and overriding the imported value.
   */
  case Live = 'live';

  /**
   * Reviewed and refused. Kept rather than deleted, because "we asked and were
   * told no" is exactly the thing someone will want to look up later — and
   * deleting it invites the same edit being proposed again next month.
   */
  case Rejected = 'rejected';

  /**
   * Replaced by a newer Live edit on the same field.
   *
   * Strictly redundant — "latest Live wins" already ignores older ones — but
   * setting it explicitly means the review queue and the history view do not
   * each have to re-derive which of several Live edits is the current one.
   */
  case Superseded = 'superseded';

  public function label(): string {
    return match ($this) {
      self::Pending    => 'Awaiting review',
      self::Live       => 'Live',
      self::Rejected   => 'Rejected',
      self::Superseded => 'Superseded',
    };
  }

  /**
   * Whether an edit in this status is still awaiting a decision.
   */
  public function isOpen(): bool {
    return $this === self::Pending;
  }
}
