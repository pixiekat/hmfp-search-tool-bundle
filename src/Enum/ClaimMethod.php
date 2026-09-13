<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Enum;

/**
 * HOW a claim came to be trusted.
 *
 * Separate from ClaimStatus because they answer different questions. The status
 * says whether the claimant may edit; the method says how much that ought to be
 * worth to somebody reading their edits later. Two Verified claims are not
 * equally convincing if one was vouched for by a steward and the other was a
 * mailbox plus a public identifier.
 *
 * Recorded on every claim so a reviewer in six months can tell the difference
 * without reconstructing it from timestamps.
 */
enum ClaimMethod: string {

  /**
   * The claimant followed an emailed link and supplied the matching NPI.
   *
   * Worth being honest about what this establishes. The account already proved
   * control of that mailbox by signing in — it came from the identity provider,
   * not from a registration form — so the email round trip adds a record and a
   * delay rather than a new factor. And the NPI is published by CMS in a free
   * public registry, so knowing it is not a secret.
   *
   * What it DOES establish: an authenticated account inside the organisation
   * deliberately asserted this identity, at a recorded time, and knew which
   * NPI belonged to the person they claimed to be. That is a reasonable bar for
   * "may propose edits to a moderated field". It is not a bar for anything that
   * publishes without review.
   */
  case SelfServiceNpi = 'self_service_npi';

  /**
   * A data steward linked the account by hand.
   *
   * The only method backed by somebody who can ring the department and ask. Also
   * the fallback for the cases self-service cannot settle: the extract has 59
   * name pairs shared by more than one physician, and a contested claim between
   * two people with the same name can only be resolved by a human.
   */
  case StewardManual = 'steward_manual';

  /**
   * The identity provider asserted it.
   *
   * Nothing emits this yet. Entra currently releases email, given name and
   * surname, none of which identify a physician uniquely. It becomes reachable
   * the day the directory releases an attribute that joins to the extract —
   * employeeId or an NPI in a directory extension — at which point claiming
   * stops being a flow a human walks through and becomes a lookup at login.
   *
   * Listed now, unused, so the column's vocabulary does not have to change when
   * that lands.
   */
  case SsoAttribute = 'sso_attribute';

  public function label(): string {
    return match ($this) {
      self::SelfServiceNpi => 'Self-service (email and NPI)',
      self::StewardManual  => 'Verified by a data steward',
      self::SsoAttribute   => 'Asserted by single sign-on',
    };
  }

  /**
   * Whether a claim verified this way should still reach the stewards' queue.
   *
   * A steward who granted the claim does not need to review their own decision.
   */
  public function requiresReview(): bool {
    return $this === self::SelfServiceNpi;
  }

  /**
   * Whether edits from this claimant may publish without review first.
   *
   * Nothing reads this yet — PhysicianEditManager::propose() publishes
   * everything immediately, by design, because until now the only people who
   * could reach it were stewards and admins.
   *
   * That assumption is what a self-service claim breaks: "a physician is
   * surprised by something published under their name" is the exact failure
   * this feature exists to prevent, and live-on-submit is how it would happen.
   * So when propose() grows a review-first path, this is the method to ask.
   * Left unused rather than guessed at, because changing publication behaviour
   * for the existing steward flow is a separate decision from adding claims.
   *
   * @see \Pixiekat\HMFPSearchToolBundle\Services\PhysicianEditManager::propose()
   */
  public function allowsImmediatePublication(): bool {
    return $this !== self::SelfServiceNpi;
  }

  /**
   * The methods a person can actually obtain today.
   *
   * Same honesty as EditableField::active() and PhysicianVocabulary::active():
   * the cases describe the intended model, this is what is wired up.
   *
   * @return list<self>
   */
  public static function active(): array {
    return [self::SelfServiceNpi, self::StewardManual];
  }
}
