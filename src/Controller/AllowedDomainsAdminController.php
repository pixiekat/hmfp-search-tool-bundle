<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Interfaces;
use Pixiekat\HMFPSearchToolBundle\Repository;
use Pixiekat\SymfonyHelpers\Interfaces as PixieInterfaces;
use Pixiekat\SymfonyHelpers\Services\AuditLogManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The allowlist of email domains that may be given local accounts.
 *
 * See AllowedEmailDomain for what it governs and — as importantly — what it
 * does not (Entra SSO).
 *
 * Behind CAN_MANAGE_USERS, the permission the Users screens use: deciding who
 * may be given an account IS managing users. That is admins and data stewards.
 *
 * One page, list and add form together, and a remove button per row. Removing a
 * domain affects FUTURE invitations only: nobody already invited or already
 * holding an account is touched, because the list is a gate on the way in, not
 * a standing condition of membership. The page says so.
 */
#[IsGranted(PixieInterfaces\Security\Voter\AdminVoterInterface::ADMIN_ADMINISTER)]
#[IsGranted(Interfaces\Security\Voter\AdminVoterInterface::PERMISSION_CAN_MANAGE_USERS)]
#[Route('/admincp/allowed-domains')]
final class AllowedDomainsAdminController extends AbstractController {

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly Repository\AllowedEmailDomainRepository $domains,
    private readonly AuditLogManager $auditLogManager,
  ) {  }

  #[Route('', name: 'hmfp_search_tool_admin_allowed_domains', methods: ['GET'])]
  public function list(): Response {
    return $this->render('@HMFPSearchTool/admin/allowed_domains/list.html.twig', [
      'domains' => $this->domains->findAllOrdered(),
    ]);
  }

  #[Route('', name: 'hmfp_search_tool_admin_allowed_domain_add', methods: ['POST'])]
  public function add(Request $request): Response {
    $back = $this->redirectToRoute('hmfp_search_tool_admin_allowed_domains');

    if (!$this->isCsrfTokenValid('allowed-domain-add', (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $back;
    }

    $typed = (string) $request->request->get('domain', '');

    try {
      $domain = new Entity\AllowedEmailDomain($typed, $this->currentUser(), (string) $request->request->get('note', ''));
    }
    catch (\InvalidArgumentException) {
      $this->addFlash('error', sprintf(
        '"%s" is not a domain this can use. Enter just the part after the @, for example bidmc.harvard.edu.',
        $typed,
      ));
      return $back;
    }

    $this->entityManager->persist($domain);

    try {
      $this->entityManager->flush();
    }
    catch (UniqueConstraintViolationException) {
      // Already listed. Not an error worth alarming anybody over — the outcome
      // they wanted is already true.
      $this->addFlash('notice', sprintf('%s is already on the list.', $domain->getDomain()));
      return $back;
    }

    $this->auditLogManager->log('allowed_domain.added', $domain, ['domain' => $domain->getDomain()]);
    $this->addFlash('success', sprintf('Addresses at %s can now be invited.', $domain->getDomain()));

    return $back;
  }

  #[Route('/{domain}/remove', name: 'hmfp_search_tool_admin_allowed_domain_remove', requirements: ['domain' => '\d+'], methods: ['POST'])]
  public function remove(Entity\AllowedEmailDomain $domain, Request $request): Response {
    $back = $this->redirectToRoute('hmfp_search_tool_admin_allowed_domains');

    if (!$this->isCsrfTokenValid('allowed-domain-remove-' . $domain->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('error', 'Invalid security token — please try again.');
      return $back;
    }

    $name = $domain->getDomain();

    // Audited BEFORE the delete, while the row still has an id to be audited by.
    $this->auditLogManager->log('allowed_domain.removed', $domain, ['domain' => $name], flush: false);
    $this->entityManager->remove($domain);
    $this->entityManager->flush();

    $this->addFlash('success', sprintf('%s removed. Nobody new at that domain can be invited; existing accounts are unaffected.', $name));

    return $back;
  }

  private function currentUser(): Entity\User {
    $user = $this->getUser();

    if (!$user instanceof Entity\User) {
      throw $this->createAccessDeniedException('Not signed in.');
    }

    return $user;
  }
}
