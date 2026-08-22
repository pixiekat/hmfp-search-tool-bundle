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

final class PhysicianController extends AbstractController {

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly RequestStack $requestStack,
    private readonly UserPasswordHasherInterface $passwordHasher,
    private readonly AuditLogManager $auditLogManager,
    private readonly Repository\PhysicianRepository $physicians,
  ) {  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/admin/physicians', name: 'hmfp_search_tool_admin_physicians_list')]
  public function listPhysicians(): Response {
    $perPage = 25;
    $page = max(1, $this->requestStack->getCurrentRequest()->query->getInt('page', 1));
    $paginator = $this->physicians->paginateStandalone($page, $perPage);
    $total = count($paginator);

    return $this->render('@HMFPSearchTool/admin/physicians/physicians_list.html.twig', [
      'physicians' => $paginator,
      'page' => $page,
      'pages' => max(1, (int) ceil($total / $perPage)),
      'total' => $total,
    ]);

  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/admin/physicians/add', name: 'hmfp_search_tool_admin_physicians_add')]
  public function addPhysician(): Response {

    $physician = new Entity\Physician();
    $form = $this->createForm(Form\AdminPhysicianFormType::class, $physician);
    $form->handleRequest($this->requestStack->getCurrentRequest());

    // process the form since we assume all passed validation in the form type.
    if ($form->isSubmitted() && $form->isValid()) {

      // is active checked, if so set the physician to active
      // normalise to boolean first.
      //$active = (bool)$form->get('active')->getData();
      //$physician->setActive($active);


      $this->entityManager->persist($physician);
      $this->entityManager->flush();
      $this->auditLogManager->log("physician.created", $physician, []);

      $this->addFlash('success', 'Physician created successfully.');

      return $this->redirectToRoute('hmfp_search_tool_admin_physicians_list');
    }

    return $this->render('@HMFPSearchTool/admin/physicians/physician_add.html.twig', [
      'form' => $form->createView(),
    ]);
  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/physician/{id}/edit', name: 'hmfp_search_tool_admin_physician_edit', requirements: ['id' => '\d+'])]
  public function editPhysician(int $id): Response {
    $physician = $this->entityManager->getRepository(Entity\Physician::class)->find($id);
    if (!$physician) {
      throw $this->createNotFoundException('Physician not found');
    }

    $form = $this->createForm(Form\AdminPhysicianFormType::class, $physician);
    $form->handleRequest($this->requestStack->getCurrentRequest());

    // process the form since we assume all passed validation in the form type.
    if ($form->isSubmitted() && $form->isValid()) {

      // is active checked, if so set the physician to active
      // normalise to boolean first.
      $active = (bool)$form->get('active')->getData();
      $physician->setActive($active);

      $this->entityManager->flush();
      $this->auditLogManager->log("physician.updated", $physician, []);

      $this->addFlash('success', 'Physician updated successfully.');

      return $this->redirectToRoute('hmfp_search_tool_admin_physicians_list');
    }

    return $this->render('@HMFPSearchTool/admin/physicians/physician_edit.html.twig', [
      'form' => $form->createView(),
      'physician' => $physician,
    ]);
  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/physician/{id}/delete', name: 'hmfp_search_tool_admin_physician_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function deletePhysician(int $id, Request $request): Response {
    $physician = $this->entityManager->getRepository(Entity\Physician::class)->find($id);
    if (!$physician) {
      throw $this->createNotFoundException('Physician not found');
    }

    if (!$this->isCsrfTokenValid('delete-physician-' . $id, (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_admin_physicians_list');
    }

    $this->entityManager->remove($physician);
    $this->entityManager->flush();
    $this->auditLogManager->log("physician.deleted", $physician, []);

    $this->addFlash('success', 'Physician deleted successfully.');
    return $this->redirectToRoute('hmfp_search_tool_admin_physicians_list');
  }
}
