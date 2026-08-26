<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Enum;

/**
 * Where an edit sits in review — AFTER it has already gone live.
 */
enum EditReviewStatus: string {

  /**
   * Live, and nobody has checked it yet. The status every edit starts in.
   */
  case Unreviewed = 'unreviewed';

  /**
   * Live, and an administrator has confirmed it.
   *
   * Changes nothing about what the public sees — the edit was already
   * published. It clears the item from the review queue and records who
   * vouched for it.
   */
  case Approved = 'approved';

  /**
   * Taken back down.
   *
   * The row is kept, not deleted: "this was published and then reverted, by
   * whom and when" is exactly what an audit trail is for, and deleting it would
   * hide that the content was ever live.
   */
  case Rejected = 'rejected';

  public function label(): string {
    return match ($this) {
      self::Unreviewed => 'Live — not yet reviewed',
      self::Approved   => 'Live — approved',
      self::Rejected   => 'Reverted',
    };
  }

  /**
   * Whether an edit in this status counts when resolving the current value.
   *
   * Both live statuses do. Only a rejection removes an edit from consideration,
   * which is what makes rejection a revert.
   */
  public function isPublished(): bool {
    return $this !== self::Rejected;
  }

  /**
   * Whether this edit still needs an administrator to look at it.
   */
  public function isAwaitingReview(): bool {
    return $this === self::Unreviewed;
  }

  /**
   * The statuses that count as published, for use in queries.
   *
   * @return list<string>
   */
  public static function publishedValues(): array {
    return [self::Unreviewed->value, self::Approved->value];
  }
}
