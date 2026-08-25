<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Interfaces\Security\Voter;

interface PhysicianVoterInterface {

  /**
   * Adds a permission to determine who can make edits to physicians;
   *  these are local edits and must be approved by a user with permission
   *  to approve edits.
   */
  public const PERMISSION_CAN_EDIT_PHYSICIAN = 'can_edit_physician';

  /**
   * Adds a permission to determine who can review and approve physician
   * edits made by other users.
   */
  public const PERMISSION_CAN_APPROVE_PHYSICIAN_EDITS = 'can_approve_physician_edits';

}
