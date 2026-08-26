<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Repository;
use Pixiekat\HMFPSearchToolBundle\Services\PhysicianEditManager;
use Pixiekat\HMFPSearchToolBundle\Services\PhysicianTaxonomyManager;
use Pixiekat\SymfonyHelpers\Interfaces as PixieInterfaces;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A Controller to handle all functionality around reviewing edits to physician records.
 *
 * Edits are proposed by physicians themselves, and are live immediately. This controller
 * is for reviewing those edits after the fact.
 */
final class PhysicianEditController extends AbstractController {

  /**
   * How many pending edits to show at once.
   *
   * A review queue is a work list rather than a report; if it is longer than
   * this the problem is that reviews are not happening, and a longer page will
   * not fix it.
   */
  private const QUEUE_SIZE = 50;

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly RequestStack $requestStack,
    private readonly Repository\PhysicianEditRepository $edits,
    private readonly PhysicianEditManager $editManager,
    private readonly PhysicianTaxonomyManager $taxonomy,
  ) {  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/admin/physician-edits', name: 'hmfp_search_tool_admin_physician_edits', methods: ['GET'])]
  public function queue(): Response {
    $pending = $this->edits->findUnreviewed(self::QUEUE_SIZE);

    // The comparison is built here rather than in Twig because working out
    // which terms are added and removed is logic, not presentation — and doing
    // it in the template would mean re-deriving it for every row in a loop.
    $comparisons = [];
    foreach ($pending as $edit) {
      $comparisons[$edit->getId()] = $this->compare($edit);
    }

    return $this->render('@HMFPSearchTool/admin/physician_edits/queue.html.twig', [
      'edits'       => $pending,
      'comparisons' => $comparisons,
      'queueSize'   => self::QUEUE_SIZE,
    ]);
  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/admin/physician-edits/{id}/approve', name: 'hmfp_search_tool_admin_physician_edit_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function approve(int $id, Request $request): Response {
    $edit = $this->edits->find($id);

    if (!$edit) {
      throw $this->createNotFoundException('Edit not found');
    }

    if (!$this->isCsrfTokenValid('approve-edit-' . $id, (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_admin_physician_edits');
    }

    // Guard against a decision being applied twice — two reviewers with the
    // queue open, or a double-submitted form. Without it the second decision
    // would overwrite the first reviewer's name and write a misleading second
    // audit entry.
    if (!$edit->getReviewStatus()->isAwaitingReview()) {
      $this->addFlash('error', sprintf(
        'That edit has already been reviewed (%s).',
        $edit->getReviewStatus()->label(),
      ));
      return $this->redirectToRoute('hmfp_search_tool_admin_physician_edits');
    }

    $user = $this->getUser();
    if (!$user instanceof Entity\User) {
      throw $this->createAccessDeniedException('Only application users can review edits.');
    }

    // approve() supersedes prior live edits, re-projects taxonomy fields and
    // writes the audit row — all without flushing, so this single flush commits
    // the whole decision atomically.
    $this->editManager->confirm($edit, $user);
    $this->entityManager->flush();

    $this->addFlash('success', sprintf(
      '%s for %s confirmed.',
      $edit->getFieldName()->label(),
      $edit->getPhysician()->getLegalName(),
    ));

    return $this->redirectToRoute('hmfp_search_tool_admin_physician_edits');
  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/admin/physician-edits/{id}/reject', name: 'hmfp_search_tool_admin_physician_edit_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function reject(int $id, Request $request): Response {
    $edit = $this->edits->find($id);

    if (!$edit) {
      throw $this->createNotFoundException('Edit not found');
    }

    if (!$this->isCsrfTokenValid('reject-edit-' . $id, (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_admin_physician_edits');
    }

    if (!$edit->getReviewStatus()->isAwaitingReview()) {
      $this->addFlash('error', sprintf(
        'That edit has already been reviewed (%s).',
        $edit->getReviewStatus()->label(),
      ));
      return $this->redirectToRoute('hmfp_search_tool_admin_physician_edits');
    }

    $user = $this->getUser();
    if (!$user instanceof Entity\User) {
      throw $this->createAccessDeniedException('Only application users can review edits.');
    }

    $this->editManager->reject($edit, $user);
    $this->entityManager->flush();

    $this->addFlash('success', sprintf(
      '%s for %s reverted.',
      $edit->getFieldName()->label(),
      $edit->getPhysician()->getLegalName(),
    ));

    return $this->redirectToRoute('hmfp_search_tool_admin_physician_edits');
  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/admin/physician-edits/history', name: 'hmfp_search_tool_admin_physician_edit_history_all', methods: ['GET'])]
  public function historyAll(): Response {
    $perPage = 25;
    $page = max(1, $this->requestStack->getCurrentRequest()->query->getInt('page', 1));
    $paginator = $this->edits->paginateStandalone($page, $perPage, ['editedAt' => 'DESC']);
    $total = count($paginator);

    return $this->render('@HMFPSearchTool/admin/physician_edits/history_all.html.twig', [
      'edits' => $paginator,
      'page' => $page,
      'pages' => max(1, (int) ceil($total / $perPage)),
      'total' => $total,
    ]);
  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/admin/physician-edits/history/{id}', name: 'hmfp_search_tool_admin_physician_edit_history', requirements: ['id' => '\d+'], methods: ['GET'])]
  public function history(int $id): Response {
    $physician = $this->entityManager->getRepository(Entity\Physician::class)->find($id);

    if (!$physician) {
      throw $this->createNotFoundException('Physician not found');
    }

    return $this->render('@HMFPSearchTool/admin/physician_edits/history.html.twig', [
      'physician' => $physician,
      'edits'     => $this->edits->findHistoryFor($physician),
    ]);
  }

  /**
   * Works out what an edit would actually change.
   *
   * Returns a shape the template can render without thinking:
   *
   *   type     'text' | 'terms'
   *   current  the value in force now
   *   proposed the value if approved
   *   added    terms that would appear    (terms only)
   *   removed  terms that would disappear (terms only)
   *
   * @return array{type: string, current: mixed, proposed: mixed, added: list<string>, removed: list<string>}
   */
  private function compare(Entity\PhysicianEdit $edit): array {
    $field     = $edit->getFieldName();
    $physician = $edit->getPhysician();

    if (!$field->isTaxonomy()) {
      return [
        'type'     => 'text',
        // The CURRENT value is the resolved one — what the public sees today —
        // not the imported value. If a previous edit is already live, that is
        // what this proposal is really replacing.
        'current'  => $this->editManager->resolve($physician, $field),
        'proposed' => $edit->getNewValue(),
        'added'    => [],
        'removed'  => [],
      ];
    }

    $vocabulary = $field->vocabulary();

    $current = [];
    if ($vocabulary !== null) {
      foreach ($this->taxonomy->termsFor($physician, $vocabulary) as $term) {
        $current[] = (string) $term->getName();
      }
    }

    $proposed = $this->decodeNames($edit->getNewValue());

    // Compared case-insensitively so a re-capitalisation is not reported as a
    // removal plus an addition — that would be noise, and the projection would
    // treat them as the same term anyway.
    $currentKeys  = array_change_key_case(array_flip(array_map(mb_strtolower(...), $current)));
    $proposedKeys = array_flip(array_map(mb_strtolower(...), $proposed));

    return [
      'type'     => 'terms',
      'current'  => $current,
      'proposed' => $proposed,
      'added'    => array_values(array_filter(
        $proposed,
        static fn (string $n): bool => !isset($currentKeys[mb_strtolower($n)]),
      )),
      'removed'  => array_values(array_filter(
        $current,
        static fn (string $n): bool => !isset($proposedKeys[mb_strtolower($n)]),
      )),
    ];
  }

  /**
   * Decodes a stored JSON name list, tolerantly.
   *
   * Mirrors PhysicianEditManager's decoder: a malformed value must render as
   * "nothing proposed" rather than taking the review queue down, because the
   * queue is precisely where someone would go to look at a broken edit.
   *
   * @return list<string>
   */
  private function decodeNames(?string $json): array {
    if ($json === null || trim($json) === '') {
      return [];
    }

    try {
      $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }

    if (!is_array($decoded)) {
      return [];
    }

    return array_values(array_filter($decoded, is_string(...)));
  }
}
