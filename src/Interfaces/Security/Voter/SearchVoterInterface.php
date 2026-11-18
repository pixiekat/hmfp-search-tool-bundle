<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Interfaces\Security\Voter;

interface SearchVoterInterface {

  /**
   * Whether the visitor can use the search tool at all.
   *
   * Open to everyone, signed in or not; SearchVoter decides it before its
   * signed-in-user guard so anonymous visitors are not bounced to /login.
   *
   * @see \Pixiekat\HMFPSearchToolBundle\Security\Voter\SearchVoter::canAccessSearch()
   */
  public const PERMISSION_CAN_ACCESS_SEARCH = 'can_access_search';

  /**
   * Checks if the user can logout.
   */
  public const PERMISSION_CAN_LOGOUT = 'can_logout';

  // No PERMISSION_CAN_VIEW_PHYSICIAN here on purpose. BaseVoter::getAttributes()
  // makes a voter support every constant it carries, and under Symfony's default
  // "affirmative" strategy one GRANT wins — so a copy of 'can_view_physician'
  // here would let SearchVoter override any restriction PhysicianVoter applies.
  // Use PhysicianVoterInterface::PERMISSION_CAN_VIEW_PHYSICIAN instead.

}
