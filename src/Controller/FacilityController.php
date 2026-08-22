<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Form;
use Pixiekat\HMFPSearchToolBundle\Repository\FacilityRepository;
use Pixiekat\SymfonyHelpers\Interfaces as PixieInterfaces;
use Pixiekat\SymfonyHelpers\Services\AuditLogManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class FacilityController extends AbstractController {

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly RequestStack $requestStack,
    private readonly UserPasswordHasherInterface $passwordHasher,
    private readonly AuditLogManager $auditLogManager,
    private readonly FacilityRepository $facilities,
  ) {  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/admin/facilities', name: 'hmfp_search_tool_admin_facilities_list')]
  public function listFacilities(): Response {
    $perPage = 25;
    $page = max(1, $this->requestStack->getCurrentRequest()->query->getInt('page', 1));
    $paginator = $this->facilities->paginateStandalone($page, $perPage);
    $total = count($paginator);

    return $this->render('@HMFPSearchTool/admin/facilities/facilities_list.html.twig', [
      'facilities' => $paginator,
      'page' => $page,
      'pages' => max(1, (int) ceil($total / $perPage)),
      'total' => $total,
    ]);
  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/admin/facilities/add', name: 'hmfp_search_tool_admin_facilities_add')]
  public function addFacility(): Response {

    $facility = new Entity\Facility();
    $form = $this->createForm(Form\AdminFacilityFormType::class, $facility);
    $form->handleRequest($this->requestStack->getCurrentRequest());

    // process the form since we assume all passed validation in the form type.
    if ($form->isSubmitted() && $form->isValid()) {

      // is active checked, if so set the department to active
      // normalise to boolean first.
      //$active = (bool)$form->get('active')->getData();
      //$department->setActive($active);


      $this->entityManager->persist($facility);
      $this->entityManager->flush();
      $this->auditLogManager->log("facility.created", $facility, []);

      $this->addFlash('success', 'Facility created successfully.');

      return $this->redirectToRoute('hmfp_search_tool_admin_facilities_list');
    }

    return $this->render('@HMFPSearchTool/admin/facilities/facility_add.html.twig', [
      'form' => $form->createView(),
    ]);
  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/facility/{id}/edit', name: 'hmfp_search_tool_admin_facility_edit', requirements: ['id' => '\d+'])]
  public function editFacility(int $id): Response {
    $facility = $this->entityManager->getRepository(Entity\Facility::class)->find($id);
    if (!$facility) {
      throw $this->createNotFoundException('Facility not found');
    }

    $form = $this->createForm(Form\AdminFacilityFormType::class, $facility);
    $form->handleRequest($this->requestStack->getCurrentRequest());

    // process the form since we assume all passed validation in the form type.
    if ($form->isSubmitted() && $form->isValid()) {

      $this->entityManager->flush();
      $this->auditLogManager->log("facility.updated", $facility, []);

      $this->addFlash('success', 'Facility updated successfully.');

      return $this->redirectToRoute('hmfp_search_tool_admin_facilities_list');
    }

    return $this->render('@HMFPSearchTool/admin/facilities/facility_edit.html.twig', [
      'form' => $form->createView(),
      'facility' => $facility,
    ]);
  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/facility/{id}/delete', name: 'hmfp_search_tool_admin_facility_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function deleteFacility(int $id, Request $request): Response {
    $facility = $this->entityManager->getRepository(Entity\Facility::class)->find($id);
    if (!$facility) {
      throw $this->createNotFoundException('Facility not found');
    }

    if (!$this->isCsrfTokenValid('delete-facility-' . $id, (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_admin_facilities_list');
    }

    $this->entityManager->remove($facility);
    $this->entityManager->flush();
    $this->auditLogManager->log("facility.deleted", $facility, []);

    $this->addFlash('success', 'Facility deleted successfully.');
    return $this->redirectToRoute('hmfp_search_tool_admin_facilities_list');
  }
}
