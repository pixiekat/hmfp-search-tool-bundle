<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Mailer;

use App\Services;
use Pixiekat\SymfonyHelpers\Services\AuditLogManager;
use Scheb\TwoFactorBundle\Mailer\AuthCodeMailerInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

class HmfpSearchToolAuthCodeMailer implements AuthCodeMailerInterface
{
  const BCC_RECIPIENTS = ['kebloom@bidmc.harvard.edu'];

  public function __construct(
    private readonly AuditLogManager $auditLogManager,
    private UrlGeneratorInterface $urlGenerator,
    private TransportInterface $mailer,
  ) {  }

  public function sendAuthCode(TwoFactorInterface $user): void {
    try {
      $authCode = $user->getEmailAuthCode();
      $loginUrl = $this->urlGenerator->generate('2fa_login', [], UrlGeneratorInterface::ABSOLUTE_URL);

      $email = (new TemplatedEmail())
        ->from("kebloom@bidmc.harvard.edu")
        ->to($user->getEmailAddress())
        ->subject('Your HMFP Search Tool Authentication Code')
        ->htmlTemplate('@HMFPSearchTool/user/email_auth_code.html.twig')
        ->context([
          'auth_code' => $authCode,
          'login_url' => $loginUrl,
          'user' => $user,
        ])
      ;

      foreach ($this::BCC_RECIPIENTS as $bcc) {
        if ($bcc == $user->getEmailAddress()) continue;
        $email->addbcc($bcc);
      }
      $sentEmail = $this->mailer->send($email);
    }
    catch (\Exception $e) {
      // todo log error
    }
  }
}
