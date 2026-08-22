<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Form;
use Pixiekat\HMFPSearchToolBundle\Repository\DepartmentRepository;
use Pixiekat\SymfonyHelpers\Interfaces as PixieInterfaces;
use Pixiekat\SymfonyHelpers\Services\AuditLogManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DepartmentController extends AbstractController {

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly RequestStack $requestStack,
    private readonly UserPasswordHasherInterface $passwordHasher,
    private readonly AuditLogManager $auditLogManager,
    private readonly DepartmentRepository $departments,
  ) {  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/admin/departments', name: 'hmfp_search_tool_admin_departments_list')]
  public function listDepartments(): Response {
    $perPage = 25;
    $page = max(1, $this->requestStack->getCurrentRequest()->query->getInt('page', 1));
    $paginator = $this->departments->paginateStandalone($page, $perPage);
    $total = count($paginator);

    return $this->render('@HMFPSearchTool/admin/departments/departments_list.html.twig', [
      'departments' => $paginator,
      'page' => $page,
      'pages' => max(1, (int) ceil($total / $perPage)),
      'total' => $total,
    ]);
  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/admin/departments/add', name: 'hmfp_search_tool_admin_departments_add')]
  public function addDepartment(): Response {

    $department = new Entity\Department();
    $form = $this->createForm(Form\AdminDepartmentFormType::class, $department);
    $form->handleRequest($this->requestStack->getCurrentRequest());

    // process the form since we assume all passed validation in the form type.
    if ($form->isSubmitted() && $form->isValid()) {

      // is active checked, if so set the department to active
      // normalise to boolean first.
      //$active = (bool)$form->get('active')->getData();
      //$department->setActive($active);


      $this->entityManager->persist($department);
      $this->entityManager->flush();
      $this->auditLogManager->log("department.created", $department, []);

      $this->addFlash('success', 'Department created successfully.');

      return $this->redirectToRoute('hmfp_search_tool_admin_departments_list');
    }

    return $this->render('@HMFPSearchTool/admin/departments/department_add.html.twig', [
      'form' => $form->createView(),
    ]);
  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/department/{id}/edit', name: 'hmfp_search_tool_admin_department_edit', requirements: ['id' => '\d+'])]
  public function editDepartment(int $id): Response {
    $department = $this->entityManager->getRepository(Entity\Department::class)->find($id);
    if (!$department) {
      throw $this->createNotFoundException('Department not found');
    }

    $form = $this->createForm(Form\AdminDepartmentFormType::class, $department);
    $form->handleRequest($this->requestStack->getCurrentRequest());

    // process the form since we assume all passed validation in the form type.
    if ($form->isSubmitted() && $form->isValid()) {

      $this->entityManager->flush();
      $this->auditLogManager->log("department.updated", $department, []);

      $this->addFlash('success', 'Department updated successfully.');

      return $this->redirectToRoute('hmfp_search_tool_admin_departments_list');
    }

    return $this->render('@HMFPSearchTool/admin/departments/department_edit.html.twig', [
      'form' => $form->createView(),
      'department' => $department,
    ]);
  }

  #[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
  #[Route('/department/{id}/delete', name: 'hmfp_search_tool_admin_department_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function deleteDepartment(int $id, Request $request): Response {
    $department = $this->entityManager->getRepository(Entity\Department::class)->find($id);
    if (!$department) {
      throw $this->createNotFoundException('Department not found');
    }

    if (!$this->isCsrfTokenValid('delete-department-' . $id, (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $this->redirectToRoute('hmfp_search_tool_admin_departments_list');
    }

    $this->entityManager->remove($department);
    $this->entityManager->flush();
    $this->auditLogManager->log("department.deleted", $department, []);

    $this->addFlash('success', 'Department deleted successfully.');
    return $this->redirectToRoute('hmfp_search_tool_admin_departments_list');
  }
}
