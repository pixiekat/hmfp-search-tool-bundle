<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Security\Voter;

use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Interfaces;
use Pixiekat\HMFPSearchToolBundle\Traits;
use Pixiekat\SymfonyHelpers\Security as PixieHelperSecurity;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

class PhysicianVoter extends PixieHelperSecurity\Voter\BaseVoter implements Interfaces\Security\Voter\PhysicianVoterInterface {
  use Traits\Security\Voter\AdminVoterTrait;

  /**
   * Physician ids each user holds a live claim on, keyed by user id.
   *
   * The search results template asks CAN_EDIT_PHYSICIAN once per row, so without
   * this the claim lookup runs once per result — twenty providers, twenty
   * queries. Fetching the whole set on the first question and answering the rest
   * from memory makes it one query per request however long the page is.
   *
   * Safe to hold on a service because the container is rebuilt per request under
   * PHP-FPM, so this cannot outlive the request that filled it. A persistent
   * worker runtime (FrankenPHP, Swoole) would keep the service alive between
   * requests and this would need clearing on each one.
   *
   * @var array<int, array<int, true>> user id → physician id → true
   */
  private array $claimedPhysicianIds = [];

  protected function supports(string $attribute, mixed $subject): bool {
    $attributes = $this->getAttributes();
    return in_array($attribute, $attributes);
  }

  protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool {
    $user = $token->getUser();

    if (!$user instanceof UserInterface) {
      return false;
    }

    $forceForAdmins = [];

    if ($this->isSysAdmin() && !in_array($attribute, $forceForAdmins)) {
      //return true;
    }
    $physician = $subject instanceof Entity\Physician ? $subject : null;

    return match($attribute) {
        self::PERMISSION_CAN_CLAIM_PHYSICIAN => $this->canClaimPhysician($user, $physician),
        self::PERMISSION_CAN_EDIT_PHYSICIAN => $this->canProposePhysicianEdits($user, $physician),
        self::PERMISSION_CAN_VIEW_PHYSICIAN_EDITS => $this->security->isGranted('ROLE_USER'),
        self::PERMISSION_CAN_VIEW_ALL_PHYSICIAN_EDITS => $this->canViewAllPhysicianEdits($user),
        self::PERMISSION_CAN_APPROVE_PHYSICIAN_EDITS => $this->canApprovePhysicianEdits($user),
        default => false,
    };

    return false;
  }

  public function canClaimPhysician(UserInterface $user, ?Entity\Physician $physician = null): bool {
    if (!$user instanceof Entity\User || $physician === null) {
      return false;   // no subject = no scoped permission
    }

    $claims = $this->entityManager->getRepository(Entity\PhysicianClaim::class);

    // Nobody claims a record somebody already holds — the holder included, since
    // they have nothing left to claim.
    if (!$claims->isPhysicianUnclaimed($physician)) {
      return false;
    }

    // Policy: one live claim per person. A second one goes through a steward.
    return !$claims->hasActiveClaims($user);
  }

  public function canProposePhysicianEdits(UserInterface $user, ?Entity\Physician $physician = null): bool {
    if (!$user instanceof Entity\User) {
      return false;
    }

    // if you can approve physician edits or you have any admin role, you can propose edits.
    if ($this->canApprovePhysicianEdits($user) || $this->hasAtLeastOneAdminRole($user, $this->getAdminRoles())) {
      //return true;
    }

    // Answered from the memoised set rather than with a query per call, because
    // the search results template asks this once per row.
    if ($physician !== null && $this->holdsClaimOn($user, $physician)) {
      return true;
    }

    // otherwise, you cannot propose edits.
    return false;
  }

  /**
   * Whether this user holds a live claim on this physician.
   *
   * Fills self::$claimedPhysicianIds on first use for the user, then answers from
   * it. array_fill_keys so the check below is an isset() on a hash rather than an
   * in_array() scan — it matters on a results page asking the question per row.
   */
  private function holdsClaimOn(Entity\User $user, Entity\Physician $physician): bool {
    $userId      = (int) $user->getId();
    $physicianId = $physician->getId();

    // An unsaved physician has no id and therefore cannot have been claimed.
    // Without this, a null id would match nothing and merely look like a miss —
    // which is the right answer, but for the wrong reason and only by accident.
    if ($physicianId === null) {
      return false;
    }

    if (!isset($this->claimedPhysicianIds[$userId])) {
      $this->claimedPhysicianIds[$userId] = array_fill_keys(
        $this->entityManager
          ->getRepository(Entity\PhysicianClaim::class)
          ->claimedPhysicianIdsFor($user),
        true,
      );
    }

    return isset($this->claimedPhysicianIds[$userId][$physicianId]);
  }

  private function canApprovePhysicianEdits(UserInterface $user): bool {
    if (!$user instanceof Entity\User) {
      return false;
    }

    // user is ROLE_ADMIN or ROLE_DATA_STEWARD. Named through the interface: this
    // line already spelled it ROLE_DATA_STEWARD while the stored role was
    // DATA_STEWARD, so it silently matched nobody until the rename.
    return $this->security->isGranted(Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_ADMIN)
      || $this->security->isGranted(Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_DATA_STEWARD);
  }

  /**
   * Whether or not a user can view all historical physician edits.
   *   For now, use self::canApprovePhysicianEdits().
   *
   * @param UserInterface $user
   * @return boolean
   */
  private function canViewAllPhysicianEdits(UserInterface $user): bool {
    return $this->canApprovePhysicianEdits($user);
  }

  private function canLogIn(UserInterface $user): bool {
    if (!$user instanceof Entity\User) {
      return false;
    }

    // check if the user is active
    if (!$user->isActive()) {
      return false;
    }

    // in the future, we may want to add more checks here, such as checking if the user has 2FA enabled and if they have completed the 2FA process. For now, we will just check if the user is active.

    return true;
  }
}
