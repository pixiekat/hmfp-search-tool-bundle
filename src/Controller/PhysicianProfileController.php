<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum\EditableField;
use Pixiekat\HMFPSearchToolBundle\Enum\EditReviewStatus;
use Pixiekat\HMFPSearchToolBundle\Enum\PhysicianVocabulary;
use Pixiekat\HMFPSearchToolBundle\Repository;
use Pixiekat\HMFPSearchToolBundle\Services\PhysicianEditManager;
use Pixiekat\HMFPSearchToolBundle\Services\PhysicianTaxonomyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Where a physician proposes changes to their own profile.
 *
 * ── Any signed-in user may edit any physician ──────────────────────────────
 * Deliberately not restricted to "your own record". Directory maintenance is
 * collaborative — a practice manager updating a colleague's interests is the
 * common case, not an attack — and every change is attributed, published with
 * an audit trail, and surfaced in the review queue. Verification happens THERE,
 * after the fact, rather than by locking the form down beforehand.
 *
 * The trade this accepts: a signed-in user can change any physician's bio, and
 * it is live immediately. That is only tolerable because three things are true
 * together — every edit names its author, every edit is reviewable, and every
 * edit is revertible without data loss. Remove any one of them and this becomes
 * the wrong design.
 *
 * ── Changes are live at once ────────────────────────────────────────────────
 * A submission publishes immediately and is flagged for review. The page says
 * so, because someone editing a colleague's profile should know it is already
 * visible rather than assuming a moderator will catch a mistake first.
 */
final class PhysicianProfileController extends AbstractController {

  /**
   * Separator for the clinical interests field.
   *
   * Newline-separated rather than comma, because clinical interests contain
   * commas — "Cardiology, non-invasive" is one interest. Asking someone to
   * escape a comma in a text box is asking them to get it wrong.
   */
  private const INTEREST_SEPARATOR = "\n";

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly Repository\PhysicianRepository $physicians,
    private readonly Repository\PhysicianEditRepository $edits,
    private readonly PhysicianEditManager $editManager,
    private readonly PhysicianTaxonomyManager $taxonomy,
  ) {  }

  #[IsGranted('ROLE_USER')]
  #[Route('/physicians/{id}/edit-profile', name: 'hmfp_search_tool_profile_edit', requirements: ['id' => '\\d+'], methods: ['GET'])]
  public function show(int $id): Response {
    return $this->render('@HMFPSearchTool/profile/edit.html.twig', $this->viewData($this->requirePhysician($id)));
  }

  #[IsGranted('ROLE_USER')]
  #[Route('/physicians/{id}/edit-profile', name: 'hmfp_search_tool_profile_submit', requirements: ['id' => '\\d+'], methods: ['POST'])]
  public function submit(int $id, Request $request): Response {
    $physician = $this->requirePhysician($id);
    $user      = $this->currentUser();

    if (!$this->isCsrfTokenValid('edit-profile-' . $id, (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_profile_edit', ['id' => $physician->getId()]);
    }

    $proposed = 0;

    // ── Bio ────────────────────────────────────────────────────────────────
    // Compared against the RESOLVED value, not the imported one: if a previous
    // edit is already live, that is what the physician is actually changing.
    // Submitting the form unchanged should propose nothing, otherwise the
    // review queue fills with no-op edits nobody can distinguish from real ones.
    $bio     = trim((string) ($request->request->all()['bio'] ?? ''));
    $liveBio = (string) ($this->editManager->resolve($physician, EditableField::Bio) ?? '');

    if ($bio !== $liveBio) {
      $this->editManager->propose(
        $physician,
        EditableField::Bio,
        $bio === '' ? null : $bio,
        $user,
      );
      $proposed++;
    }

    // ── Clinical interests ─────────────────────────────────────────────────
    $interests = $this->splitInterests((string) ($request->request->all()['interests'] ?? ''));
    $current   = array_map(
      static fn ($term): string => (string) $term->getName(),
      $this->taxonomy->termsFor($physician, PhysicianVocabulary::ClinicalInterest),
    );

    if ($this->differs($interests, $current)) {
      $this->editManager->proposeTerms(
        $physician,
        EditableField::ClinicalInterests,
        $interests,
        $user,
      );
      $proposed++;
    }

    if ($proposed === 0) {
      $this->addFlash('notice', 'Nothing changed, so nothing was saved.');
      return $this->redirectToRoute('hmfp_search_tool_profile_edit', ['id' => $physician->getId()]);
    }

    $this->entityManager->flush();

    $this->addFlash('success', sprintf(
      '%d change%s published and flagged for review.',
      $proposed,
      $proposed === 1 ? '' : 's',
    ));

    return $this->redirectToRoute('hmfp_search_tool_profile_edit', ['id' => $physician->getId()]);
  }

  /**
   * Everything the form needs.
   *
   * @return array<string, mixed>
   */
  private function viewData(Entity\Physician $physician): array {
    $history = $this->edits->findHistoryFor($physician);

    return [
      'physician' => $physician,

      // Pre-filled with what is LIVE, so the form is a starting point for the
      // next change rather than a blank slate that silently discards the
      // current value if submitted untouched.
      'bio' => $this->editManager->resolve($physician, EditableField::Bio),
      'interests' => implode(self::INTEREST_SEPARATOR, array_map(
        static fn ($term): string => (string) $term->getName(),
        $this->taxonomy->termsFor($physician, PhysicianVocabulary::ClinicalInterest),
      )),

      // Live-but-unreviewed edits, shown back so the editor knows a reviewer
      // will be looking at them. The form fields already show these values,
      // because they are live.
      'awaitingReview' => array_values(array_filter(
        $history,
        static fn (Entity\PhysicianEdit $e): bool => $e->getReviewStatus()->isAwaitingReview(),
      )),

      'recent' => array_slice(array_values(array_filter(
        $history,
        static fn (Entity\PhysicianEdit $e): bool => !$e->getReviewStatus()->isAwaitingReview(),
      )), 0, 5),
    ];
  }

  /**
   * The physician being edited.
   *
   * No ownership check — see the class docblock. The route is behind ROLE_USER
   * and everything beyond that is handled by attribution and review.
   */
  private function requirePhysician(int $id): Entity\Physician {
    $physician = $this->physicians->find($id);

    if ($physician === null) {
      throw $this->createNotFoundException('Physician not found.');
    }

    return $physician;
  }

  private function currentUser(): Entity\User {
    $user = $this->getUser();

    if (!$user instanceof Entity\User) {
      throw $this->createAccessDeniedException('Not signed in.');
    }

    return $user;
  }

  /**
   * Splits the interests textarea into a clean list.
   *
   * @return list<string>
   */
  private function splitInterests(string $raw): array {
    $names = [];

    // Normalising CRLF first: a browser submits \r\n from a textarea, and a
    // trailing \r would otherwise become part of every interest name — invisible
    // on screen and a different string to the database.
    foreach (explode("\n", str_replace("\r\n", "\n", $raw)) as $line) {
      $line = trim((string) preg_replace('/\s+/u', ' ', $line));

      if ($line !== '') {
        $names[] = $line;
      }
    }

    return $names;
  }

  /**
   * Whether two name lists differ, ignoring case and order.
   *
   * Order-insensitive because the stored list has no meaningful order — it is
   * projected into a set of links — and case-insensitive because the projection
   * treats "Heart failure" and "heart failure" as one term. Without both, simply
   * reordering the textarea would queue a review that changes nothing.
   *
   * @param list<string> $a
   * @param list<string> $b
   */
  private function differs(array $a, array $b): bool {
    $normalise = static function (array $names): array {
      $names = array_map(mb_strtolower(...), $names);
      $names = array_unique($names);
      sort($names);

      return $names;
    };

    return $normalise($a) !== $normalise($b);
  }
}
