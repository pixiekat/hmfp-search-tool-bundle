<?php
namespace Pixiekat\HMFPSearchToolBundle\Traits\Security\Voter;
use Pixiekat\HMFPSearchToolBundle\Interfaces;

trait AdminVoterTrait {

    protected function getAdminRoles(): array {
      // NOT ROLE_ANALYTICS_VIEWER. That role exists to read reports and nothing
      // else, and this list is what hasAtLeastOneAdminRole() answers from — so
      // listing it here made every "is this an admin?" check in the application say
      // yes, which let a reports-only account read /admincp/users. The role
      // hierarchy already grants ROLE_ADMIN everything the viewer has; naming the
      // leaf role here made the implication run backwards.
      //
      // Reports are gated by PERMISSION_CAN_ACCESS_REPORTS on ReportsController.
      return [
        'ROLE_SUPER_ADMIN',
        Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_SYSADMIN,
        Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_ADMIN,
        Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_CONTENT_ADMIN,
        Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_DATA_STEWARD,
        Interfaces\Entity\HMFPSearchToolUserInterface::ROLE_DEPARTMENT_EDITOR,
      ];
    }

}
