<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Interfaces\Security\Voter;

interface SearchVoterInterface {

  /**
   * Adds a permission to search if they can access the search tool at all.
   * For now, we don't expose it to people who aren't logged on.
   */
  public const PERMISSION_CAN_ACCESS_SEARCH = 'can_access_search';

}
