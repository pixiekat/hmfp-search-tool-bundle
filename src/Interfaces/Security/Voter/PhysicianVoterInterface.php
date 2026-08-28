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

  /**
   * View all physician edits. This allows the user to view a historical list of approved and live edits
   * made to a physician profile.
   */
  public const PERMISSION_CAN_VIEW_PHYSICIAN_EDITS = 'can_view_physician_edits';

  /**
   * View all physician edits, including rejected edits. This allows the user to view a historical list of approved, rejected, and live edits made to a physician profile.
   */
  public const PERMISSION_CAN_VIEW_ALL_PHYSICIAN_EDITS = 'can_view_all_physician_edits';

}
