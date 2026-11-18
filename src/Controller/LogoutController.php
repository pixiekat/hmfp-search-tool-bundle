<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The "Log out?" page.
 *
 * LogoutRequestGuard sends every logout-path request that is NOT a proper
 * logout (a GET from an old bookmark or stale tab, or a POST whose CSRF token
 * expired) here, instead of logging out. This page then offers the real
 * logout button.
 *
 * It is an ordinary route — rather than a page rendered by the guard — so it
 * runs through the firewall and knows who is signed in.
 *
 * The path sits under /logout, which security.yaml's access_control already
 * marks PUBLIC_ACCESS.
 *
 * @see \Pixiekat\HMFPSearchToolBundle\EventListener\LogoutRequestGuard
 */
final class LogoutController extends AbstractController {

  /**
   * Route name, shared with LogoutRequestGuard so the two cannot drift apart.
   */
  public const CONFIRM_ROUTE = 'hmfp_search_tool_logout_confirm';

  /**
   * Shows the confirmation page, or sends signed-out visitors home.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; ?expired=1 means a logout form's token had run out.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The page, or a redirect home when nobody is signed in.
   */
  #[Route('/logout/confirm', name: self::CONFIRM_ROUTE, methods: ['GET'])]
  public function confirm(Request $request): Response {
    // Nobody to log out: asking "Log out?" would only confuse.
    if ($this->getUser() === null) {
      return $this->redirectToRoute('hmfp_search_tool_home');
    }

    $response = $this->render('@HMFPSearchTool/security/logout_confirm.html.twig', [
      'expired' => $request->query->getBoolean('expired'),
    ]);

    // Contains a CSRF token tied to this session; never cache it.
    $response->headers->set('Cache-Control', 'no-store, private');

    return $response;
  }
}
