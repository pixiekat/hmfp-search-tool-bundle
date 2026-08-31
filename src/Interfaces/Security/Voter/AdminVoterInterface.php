<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Interfaces\Security\Voter;

use Pixiekat\SymfonyHelpers\Interfaces\Security\Voter\AdminVoterInterface as HelpersAdminVoterInterface;

/**
 * The attributes this application's AdminVoter answers for.
 *
 * Extending the helpers' interface is not cosmetic — it is the whole mechanism
 * by which ADMIN_ADMINISTER becomes ours to answer. BaseVoter::getAttributes()
 * builds its supported-attribute list by reflecting over the *constants visible
 * on the voter class*, which means constants reachable through its parents and
 * its interfaces. Referencing the helpers' constant from inside
 * voteOnAttribute() does nothing for that list, so before this `extends` the
 * bundle voter's supports() returned false for 'administer admin' and the voter
 * abstained on every admin route — the match arm for it was unreachable code.
 *
 * PHP 8.1+ also permits redeclaring an inherited interface constant, so a future
 * app could give ADMIN_ADMINISTER a different attribute string here. We keep the
 * inherited value: the point is to override the *decision*, not the name.
 */
interface AdminVoterInterface extends HelpersAdminVoterInterface {

  public const PERMISSION_CAN_ACCESS_ADMIN = 'can_access_admin';
  public const PERMISSION_CAN_ACCESS_REPORTS = 'can_access_reports';
  public const PERMISSION_CAN_MANAGE_USERS = 'can_manage_users';
  public const PERMISSION_CAN_APPROVE_EDITS = 'can_approve_edits';
  public const ADMIN_ADMINISTER = HelpersAdminVoterInterface::ADMIN_ADMINISTER;

}
