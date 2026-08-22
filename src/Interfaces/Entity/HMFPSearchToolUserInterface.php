<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Interfaces\Entity;

interface HMFPSearchToolUserInterface {

  /**
   * The role for the sys admin.
   */
  public const ROLE_SYSADMIN = 'ROLE_SYSADMIN';

  /**
   * The label for the sys admin role.
   */
  public const ROLE_SYSADMIN_LABEL = 'System Administrator';

  /**
   * The role for the admin.
   */
  public const ROLE_ADMIN = 'ROLE_ADMIN';

  /**
   * The label for the admin role.
   */
  public const ROLE_ADMIN_LABEL = 'Admin';

  /**
   * The role for the content admin.
   */
  public const ROLE_CONTENT_ADMIN = 'CONTENT_ADMIN';

  /**
   * The label for the content admin role.
   */
  public const ROLE_CONTENT_ADMIN_LABEL = 'Content Administrator';

  /**
   * The role for the data steward.
   */
  public const ROLE_DATA_STEWARD = 'DATA_STEWARD';

  /**
   * The label for the data steward role.
   */
  public const ROLE_DATA_STEWARD_LABEL = 'Data Steward';

  /**
   * The role for the department editor.
   */
  public const ROLE_DEPARTMENT_EDITOR = 'DEPARTMENT_EDITOR';

  /**
   * The label for the department editor role.
   */
  public const ROLE_DEPARTMENT_EDITOR_LABEL = 'Department Editor';

  /**
   * The role for the analytics viewer.
   */
  public const ROLE_ANALYTICS_VIEWER = 'ANALYTICS_VIEWER';

  /**
   * The label for the analytics viewer role.
   */
  public const ROLE_ANALYTICS_VIEWER_LABEL = 'Analytics Viewer';

  /**
   * The role for the basic user.
   */
  public const ROLE_USER = 'ROLE_USER';

  /**
   * The label for the basic user role.
   */
  public const ROLE_USER_LABEL = 'User';

  /**
   * Returns an array of all the roles.
   *
   * @param bool $includeUser Whether to include the ROLE_USER role in the returned array.
   * @param bool $invert Whether to invert the key/value pairs in the returned array. If true, the role names will be the keys and the labels will be the values.
   * @return array An array of all the roles.
   */
  public static function getAllRoles(bool $includeUser = true, bool $invert = false): array;

}
