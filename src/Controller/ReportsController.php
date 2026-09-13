<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Interfaces;
use Pixiekat\HMFPSearchToolBundle\Repository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only reporting, split out of AdminController.
 *
 * ── WHY THIS IS A SEPARATE CONTROLLER ──────────────────────────────────────
 * ROLE_ANALYTICS_VIEWER exists to look at reports and nothing else. Getting that
 * to be TRUE needs all three of these, and none of them works alone:
 *
 *   1. security.yaml's ^/admincp rule lists the role, so they can reach the URL
 *      prefix at all. Without it the firewall answers 403 before any voter runs,
 *      and CAN_ACCESS_REPORTS is never consulted — which is precisely the state
 *      this split was written to fix.
 *
 *   2. AdminVoterTrait::getAdminRoles() does NOT list the role, so
 *      hasAtLeastOneAdminRole() is false for them and AdminController's
 *      class-level ADMIN_ADMINISTER refuses. Listing a leaf role there made every
 *      "is this an admin?" check in the application answer yes, which is how a
 *      reports-only account could read /admincp/users. The role hierarchy already
 *      gives ROLE_ADMIN everything the viewer has; the trait was making the
 *      implication run backwards.
 *
 *   3. This controller carries its own gate. A method-level #[IsGranted] does not
 *      override a class-level one — both must pass — so while these actions lived
 *      on AdminController they could never be more restricted, or differently
 *      restricted, than ADMIN_ADMINISTER. Moving them is the only way the
 *      permission gets to be the thing that decides.
 *
 * The upshot is that the URL prefix and the permission now agree, and adding a new
 * action to AdminController can no longer expose anything to a reports-only role
 * by accident.
 *
 * Still under /admincp, deliberately: it IS part of the control panel, and moving
 * it out of the prefix would mean a second access_control rule to keep in step.
 */
#[IsGranted(Interfaces\Security\Voter\AdminVoterInterface::PERMISSION_CAN_ACCESS_REPORTS)]
#[Route('/admincp/reports')]
final class ReportsController extends AbstractController {

  /**
   * How far back the "recently searched for" figures look.
   */
  private const TREND_DAYS = 30;

  /**
   * How many rows the top-terms table shows.
   */
  private const TOP_TERMS = 10;

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly Repository\SearchEventRepository $searchEvents,
  ) {  }

  /**
   * Directory totals and what people have been searching for.
   */
  #[Route('/search', name: 'hmfp_search_tool_statistics')]
  public function searchStats(): Response {
    // Route name kept as hmfp_search_tool_statistics even though the path moved, so
    // that existing links and any bookmarks in somebody's browser keep resolving.
    // A route name is an internal identifier; renaming it would mean touching every
    // path() call for no gain.
    $since = new \DateTimeImmutable(sprintf('-%d days', self::TREND_DAYS));

    return $this->render('@HMFPSearchTool/admin/search_stats.html.twig', [
      'totalPhysicians'  => $this->entityManager->getRepository(Entity\Physician::class)->count([]),
      'totalDepartments' => $this->entityManager->getRepository(Entity\Department::class)->count([]),
      'totalFacilities'  => $this->entityManager->getRepository(Entity\Facility::class)->count([]),

      // Built from a relative DateTimeImmutable rather than
      // createFromFormat('Y-m-d', date('Y-m-d', strtotime(...))), which was a round
      // trip through two string formats to land on midnight — and silently returns
      // false rather than a date if the format ever fails to match.
      'topMatchedTerms'  => $this->searchEvents->topMatchedTerms($since, self::TOP_TERMS),
    ]);
  }
}
