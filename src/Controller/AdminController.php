<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Form;
use Pixiekat\HMFPSearchToolBundle\Repository;
use Pixiekat\SymfonyHelpers\Interfaces as PixieInterfaces;
use Pixiekat\SymfonyHelpers\Services\AuditLogManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
#[Route('/admincp')]
final class AdminController extends AbstractController {

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly RequestStack $requestStack,
    private readonly UserPasswordHasherInterface $passwordHasher,
    private readonly AuditLogManager $auditLogManager,
    private readonly Repository\PhysicianClaimRepository $claims,
  ) {  }

  // get all claims for an admin to review, with a link to the claim review page for each claim.
  #[Route('/claims', name: 'hmfp_search_tool_admin_claims')]
  public function listClaims(): Response {
    $perPage = 25;
    $page = max(1, $this->requestStack->getCurrentRequest()->query->getInt('page', 1));
    $paginator = $this->entityManager->getRepository(Entity\PhysicianClaim::class)->paginateStandalone($page, $perPage, ['claimedAt' => 'DESC']);
    $total = count($paginator);
    return $this->render('@HMFPSearchTool/admin/claims/claims_list.html.twig', [
      'claims' => $paginator,
      'page' => $page,
      'pages' => max(1, (int) ceil($total / $perPage)),
      'total' => $total,
    ]);
  }

  /**
   * The stewards' work queue: claims still needing a human, oldest first.
   *
   * Separate from listClaims(), which is the full history newest-first. A queue and
   * an archive answer different questions — "what do I have to do" versus "what
   * happened to this record" — and one list ordered one way cannot do both. Same
   * split as physician_edits' queue.html.twig alongside history_all.html.twig.
   *
   * Not paginated on purpose: if this needs more than one page, the queue is not
   * being worked and the answer is staffing rather than paging. The limit is a
   * safety valve, and the heading says the true total so a backlog is visible
   * rather than quietly truncated.
   */
  #[Route('/claims/queue', name: 'hmfp_search_tool_admin_claims_queue')]
  public function claimsQueue(): Response {
    $claims = $this->claims->findOpenQueueWithRelations();

    return $this->render('@HMFPSearchTool/admin/claims/claims_queue.html.twig', [
      'claims' => $claims,
      'total'  => $this->claims->countOpen(),
      'shown'  => count($claims),
    ]);
  }

  #[Route('/claim/{id}/edit', name: 'hmfp_search_tool_admin_claim_edit', requirements: ['id' => '\d+'])]
  public function editClaim(Entity\PhysicianClaim $claim): Response {
    $form = $this->createForm(Form\ClaimEditFormType::class, $claim);
    $form->handleRequest($this->requestStack->getCurrentRequest());
    if ($form->isSubmitted() && $form->isValid()) {
      $this->entityManager->flush();
      return $this->redirectToRoute('hmfp_search_tool_admin_claims');
    }
    return $this->render('@HMFPSearchTool/admin/claims/claim_edit.html.twig', [
      'claim' => $claim,
      'form' => $form,
    ]);
  }

  #[Route('/users', name: 'hmfp_search_tool_admin_users_list')]
  public function listUsers(): Response {
    $users = $this->entityManager->getRepository(Entity\User::class)->findAll();
    return $this->render('@HMFPSearchTool/admin/users/users_list.html.twig', [
      'users' => $users,
    ]);
  }

  #[Route('/user/add', name: 'hmfp_search_tool_admin_user_add')]
  public function addUser(): Response {

    $user = new Entity\User();
    $form = $this->createForm(Form\AdminUserFormType::class, $user);
    $form->handleRequest($this->requestStack->getCurrentRequest());

    // process the form since we assume all passed validation in the form type.
    if ($form->isSubmitted() && $form->isValid()) {

      // is active checked, if so set the user to active
      // normalise to boolean first.
      $active = (bool)$form->get('active')->getData();
      $user->setActive($active);

      // our roles are ROLE_ADMIN and ROLE_EVALUATOR, multiple select. if any are checked, add the roles to the user. ROLE USER is always added by default.
      $roles = $form->get('roles')->getData();
      if (is_array($roles)) {
        $user->setRoles($roles);
      }

      // obviously we're adding a user so we always have a password.
      // hash it and set the password on the user entity.
      $plainPassword = (string)$form->get('plainPassword')->getData();
      $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
      $user->setPassword($hashedPassword);

      $this->entityManager->persist($user);
      $this->entityManager->flush();
      $this->auditLogManager->log("user.created", $user, []);

      $this->addFlash('success', 'User created successfully.');

      return $this->redirectToRoute('hmfp_search_tool_admin_users_list');
    }

    return $this->render('@HMFPSearchTool/admin/users/user_add.html.twig', [
      'form' => $form->createView(),
    ]);
  }

  #[Route('/user/{id}/edit', name: 'hmfp_search_tool_admin_user_edit', requirements: ['id' => '\d+'])]
  public function editUser(int $id): Response {
    $user = $this->entityManager->getRepository(Entity\User::class)->find($id);
    if (!$user) {
      throw $this->createNotFoundException('User not found');
    }

    $form = $this->createForm(Form\AdminUserFormType::class, $user);
    $form->handleRequest($this->requestStack->getCurrentRequest());

    // process the form since we assume all passed validation in the form type.
    if ($form->isSubmitted() && $form->isValid()) {

      // passwords are cast to string in the form type listener, so we'll just see if we need to
      // hash based on whether the string is empty or not. If it's empty, the admin didn't want to change it.
      $plainPassword = (string)$form->get('plainPassword')->getData();
      if ($plainPassword !== '') {
        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashedPassword);
      }

      // is active checked, if so set the user to active
      // normalise to boolean first.
      $active = (bool)$form->get('active')->getData();
      $user->setActive($active);

      // our roles are ROLE_ADMIN and ROLE_EVALUATOR, multiple select. if any are checked, add the roles to the user. ROLE USER is always added by default.
      $roles = $form->get('roles')->getData();
      if (is_array($roles)) {
        $user->setRoles($roles);
      }

      $this->entityManager->flush();
      $this->auditLogManager->log("user.updated", $user, []);

      $this->addFlash('success', $plainPassword !== ''
        ? 'User updated and password changed.'
        : 'User updated. Password left unchanged.');

      return $this->redirectToRoute('hmfp_search_tool_admin_users_list');
    }

    return $this->render('@HMFPSearchTool/admin/users/user_edit.html.twig', [
      'form' => $form->createView(),
      'user' => $user,
    ]);
  }

  #[Route('/user/{id}/delete', name: 'hmfp_search_tool_admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function deleteUser(int $id, Request $request): Response {
    $user = $this->entityManager->getRepository(Entity\User::class)->find($id);
    if (!$user) {
      throw $this->createNotFoundException('User not found');
    }

    if (!$this->isCsrfTokenValid('delete-user-' . $id, (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_admin_users_list');
    }

    $this->entityManager->remove($user);
    $this->entityManager->flush();
    $this->auditLogManager->log("user.deleted", $user, []);

    $this->addFlash('success', 'User deleted successfully.');
    return $this->redirectToRoute('hmfp_search_tool_admin_users_list');
  }
}
