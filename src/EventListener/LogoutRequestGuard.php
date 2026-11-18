<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\EventListener;

use Pixiekat\HMFPSearchToolBundle\Controller\LogoutController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Decides which requests to the logout path are allowed to actually log out.
 *
 * The firewall's logout listener signs out ANY request to /saml/logout,
 * including a plain GET. Two real problems followed from that:
 *
 *   1. Turbo 8 prefetches links on hover. Hovering over "Log out" sent a GET
 *      to /saml/logout and signed the user out before they clicked anything.
 *   2. Any other website could sign our users out by embedding
 *      <img src=".../saml/logout"> (logout CSRF).
 *
 * The obvious fix, `logout: { enable_csrf: true }` in security.yaml, would
 * break single logout: the identity provider sends its SLO messages back to
 * the same path as a GET carrying ?SAMLRequest= or ?SAMLResponse=, with no
 * token of ours. So the rule lives here instead, where it can tell the two
 * kinds of traffic apart:
 *
 *   - IdP SLO messages (SAMLRequest / SAMLResponse): pass through untouched.
 *     They are signed by the IdP and verified by the SAML bundle.
 *   - Prefetches (Sec-Purpose / X-Sec-Purpose: prefetch): 204, nothing else.
 *   - POST with a valid 'logout' CSRF token: pass through; the firewall then
 *     logs the user out exactly as before.
 *   - Anything else (a GET from an old bookmark, a stale tab, a forged POST):
 *     redirect to a "Log out?" page with a real logout button. This does NOT
 *     log out, and the page says so plainly, so nobody on a shared computer
 *     walks away believing they are signed out when they are not.
 *
 * Runs at priority 16: after the router (32), so the matched route is known,
 * and before the firewall (8), so the firewall never sees a request we refuse.
 *
 * Why redirect rather than render the confirmation page here: at priority 16
 * the firewall has not loaded the user yet, so a page rendered from here would
 * think nobody is signed in (the header would offer "Log in"). The redirect
 * target is an ordinary route that runs through the firewall like any other.
 *
 * @see templates/security/_logout_form.html.twig  The form every logout control uses.
 * @see \Pixiekat\HMFPSearchToolBundle\Controller\LogoutController  The "Log out?" page.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class LogoutRequestGuard {

  /**
   * Route name of the firewall's logout path (security.yaml: logout.path).
   */
  private const LOGOUT_ROUTE = 'saml_logout';

  /**
   * CSRF token id. Must match csrf_token('logout') in _logout_form.html.twig.
   */
  public const CSRF_TOKEN_ID = 'logout';

  /**
   * Form field carrying the token.
   *
   * Deliberately NOT `_csrf_token`: the 2FA form's Cancel button posts the
   * whole 2FA form, which already has its own `_csrf_token` field later in
   * the markup, and PHP keeps only the last field with a given name.
   */
  public const CSRF_FIELD = '_logout_token';

  /**
   * @param \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrfTokens
   *   Session-backed token manager (stateless CSRF is not enabled here).
   * @param \Symfony\Component\Routing\Generator\UrlGeneratorInterface $urls
   *   Builds the confirmation page URL.
   */
  public function __construct(
    private readonly CsrfTokenManagerInterface $csrfTokens,
    private readonly UrlGeneratorInterface $urls,
  ) {}

  /**
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The kernel request event.
   */
  public function __invoke(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== self::LOGOUT_ROUTE) {
      return;
    }

    // Identity-provider single logout. Leave it entirely to the SAML bundle.
    if ($request->query->has('SAMLRequest') || $request->query->has('SAMLResponse')) {
      return;
    }

    // Hover prefetch (Turbo sends X-Sec-Purpose; browsers' own speculative
    // loading sends Sec-Purpose). Answer with nothing and change nothing.
    if ($this->isPrefetch($request)) {
      $event->setResponse($this->noStore(new Response('', Response::HTTP_NO_CONTENT)));
      return;
    }

    // The one way in for a user-initiated logout.
    if ($request->isMethod('POST') && $this->hasValidToken($request)) {
      return;
    }

    // Everything else goes to the confirmation page. A POST that got here had
    // a missing or expired token, and the page says so; a GET is an ordinary
    // visit. 303 so the browser follows with a GET whatever the method was.
    $params = $request->isMethod('POST') ? ['expired' => 1] : [];
    $event->setResponse($this->noStore(new RedirectResponse(
      $this->urls->generate(LogoutController::CONFIRM_ROUTE, $params),
      Response::HTTP_SEE_OTHER,
    )));
  }

  /**
   * Whether the request is a speculative prefetch rather than a navigation.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return bool
   *   TRUE when either purpose header mentions "prefetch".
   */
  private function isPrefetch(Request $request): bool {
    foreach (['Sec-Purpose', 'X-Sec-Purpose', 'Purpose'] as $header) {
      if (str_contains(strtolower((string) $request->headers->get($header, '')), 'prefetch')) {
        return true;
      }
    }

    return false;
  }

  /**
   * Whether the POST carries a valid logout CSRF token.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return bool
   *   TRUE when the token matches the one issued to this session.
   */
  private function hasValidToken(Request $request): bool {
    $value = (string) $request->request->get(self::CSRF_FIELD, '');

    return $value !== ''
      && $this->csrfTokens->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $value));
  }

  /**
   * Stops browsers and proxies caching a logout-path response.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response to mark.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The same response, marked private and no-store.
   */
  private function noStore(Response $response): Response {
    $response->headers->set('Cache-Control', 'no-store, private');

    return $response;
  }
}
