<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum\EditableField;
use Pixiekat\HMFPSearchToolBundle\Enum\EditReviewStatus;
use Pixiekat\HMFPSearchToolBundle\Enum\PhysicianVocabulary;
use Pixiekat\HMFPSearchToolBundle\Interfaces;
use Pixiekat\HMFPSearchToolBundle\Repository;
use Pixiekat\HMFPSearchToolBundle\Services\PhysicianEditManager;
use Pixiekat\HMFPSearchToolBundle\Services\PhysicianTaxonomyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A controller for viewing and editing physician profiles.
 */
final class PhysicianProfileController extends AbstractController {

  /**
   * Separator for the one-per-line interest textareas.
   */
  private const INTEREST_SEPARATOR = "\n";

  /**
   * The taxonomy-backed textareas on the edit form, keyed by their POST name.
   *
   * One map drives the pre-fill (viewData), the submit loop, and the profile
   * view, so adding another one-per-line taxonomy field is one line here plus
   * its markup. The keys are also the Twig variable names the templates read.
   *
   * 'interests' keeps its original name for clinical interests so existing
   * templates and any bookmarked/in-flight forms keep working.
   *
   * @var array<string, EditableField>
   */
  private const TERM_FIELDS = [
    'interests'          => EditableField::ClinicalInterests,
    'research_interests' => EditableField::ResearchInterests,
  ];

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly Repository\PhysicianRepository $physicians,
    private readonly Repository\PhysicianClaimRepository $claims,
    private readonly Repository\PhysicianEditRepository $edits,
    private readonly PhysicianEditManager $editManager,
    private readonly PhysicianTaxonomyManager $taxonomy,
  ) {  }

  #[IsGranted('ROLE_USER')]
  #[Route('/physicians/{id}', name: 'hmfp_search_tool_profile_view', requirements: ['id' => '\\d+'], methods: ['GET'])]
  public function viewPhysician(Entity\Physician $physician): Response {
    $edits = $this->edits->findHistoryFor($physician);
    $hasClaim = $this->claims->isPhysicianUnclaimed($physician) === false;
    $last_edit = null;
    if (!empty($edits)) {
      $last_edit = $edits[0];
    }

    return $this->render('@HMFPSearchTool/profile/view.html.twig', [
      'physician' => $physician,
      'bio' => $this->editManager->resolve($physician, EditableField::Bio),
      // Term entities (not names) under each TERM_FIELDS key, so the view can
      // link every term to a search for it.
      ...$this->termsByField($physician),
      'edits' => $edits,
      'has_claim' => $hasClaim,
      'lastSeenInImportAt' => $physician->getLastSeenInImportAt(),
      'last_edit' => $last_edit,
    ]);
  }

  #[IsGranted(Interfaces\Security\Voter\PhysicianVoterInterface::PERMISSION_CAN_EDIT_PHYSICIAN, subject: 'physician')]
  #[Route('/physicians/{physician}/edit', name: 'hmfp_search_tool_profile_edit', requirements: ['physician' => '\\d+'], methods: ['GET'])]
  public function editPhysician(Entity\Physician $physician): Response {
    return $this->render('@HMFPSearchTool/profile/edit.html.twig', $this->viewData($this->requirePhysician($physician->getId())));
  }

  // The route placeholder is named {physician} so that the resolver hydrates the
  // entity AND #[IsGranted] can name it as its subject. With an {id} placeholder
  // and an int argument there is no 'physician' argument for the attribute to
  // point at, and the voter is handed nothing to scope against.
  #[IsGranted(Interfaces\Security\Voter\PhysicianVoterInterface::PERMISSION_CAN_EDIT_PHYSICIAN, subject: 'physician')]
  #[Route('/physicians/{physician}/edit-profile', name: 'hmfp_search_tool_profile_submit', requirements: ['physician' => '\\d+'], methods: ['POST'])]
  public function submit(Entity\Physician $physician, Request $request): Response {
    $user = $this->currentUser();

    if (!$this->isCsrfTokenValid('edit-profile-' . $physician->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_profile_edit', ['physician' => $physician->getId()]);
    }

    $proposed = 0;

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

    // Each taxonomy textarea is compared against what is LIVE, for the same
    // no-op reason as the bio above. A field missing from the POST entirely is
    // treated as empty, matching how the bio behaves.
    foreach (self::TERM_FIELDS as $input => $field) {
      $names = $this->splitInterests((string) ($request->request->all()[$input] ?? ''));

      if ($this->differs($names, $this->termNames($physician, $field))) {
        $this->editManager->proposeTerms($physician, $field, $names, $user);
        $proposed++;
      }
    }

    if ($proposed === 0) {
      $this->addFlash('notice', 'Nothing changed, so nothing was saved.');
      return $this->redirectToRoute('hmfp_search_tool_profile_edit', ['physician' => $physician->getId()]);
    }

    $this->entityManager->flush();

    $this->addFlash('success', sprintf(
      '%d change%s published and flagged for review.',
      $proposed,
      $proposed === 1 ? '' : 's',
    ));

    return $this->redirectToRoute('hmfp_search_tool_profile_edit', ['physician' => $physician->getId()]);
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
      // One newline-joined string per TERM_FIELDS key, ready for a textarea.
      ...array_map(
        fn (EditableField $field): string => implode(self::INTEREST_SEPARATOR, $this->termNames($physician, $field)),
        self::TERM_FIELDS,
      ),

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
   * The live terms for every TERM_FIELDS field, keyed by its form/Twig name.
   *
   * @return array<string, list<\Pixiekat\SymfonyHelpers\Entity\Term>>
   */
  private function termsByField(Entity\Physician $physician): array {
    return array_map(
      fn (EditableField $field): array => $this->taxonomy->termsFor($physician, $this->vocabularyOf($field)),
      self::TERM_FIELDS,
    );
  }

  /**
   * The NAMES of a field's live terms, for pre-filling and diffing.
   *
   * Names, never display labels: the name is what proposeTerms() matches on,
   * so pre-filling with a label would make an untouched form look changed and
   * would create a duplicate term spelled like the label.
   *
   * @return list<string>
   */
  private function termNames(Entity\Physician $physician, EditableField $field): array {
    return array_map(
      static fn ($term): string => (string) $term->getName(),
      $this->taxonomy->termsFor($physician, $this->vocabularyOf($field)),
    );
  }

  /**
   * A taxonomy field's vocabulary, failing loudly if it has none.
   *
   * Only reachable if someone adds a scalar field to TERM_FIELDS.
   */
  private function vocabularyOf(EditableField $field): PhysicianVocabulary {
    return $field->vocabulary()
      ?? throw new \LogicException(sprintf('%s is not a taxonomy field.', $field->value));
  }

  /**
   * Splits an interests textarea into a clean list.
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
