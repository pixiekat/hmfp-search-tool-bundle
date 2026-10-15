<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Security;

use Pixiekat\HMFPSearchToolBundle\Entity\User;
use Nbgrp\OneloginSamlBundle\Security\User\SamlUserFactoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class SamlUserFactory implements SamlUserFactoryInterface {
  public function createUser(string $identifier, array $attributes): UserInterface {

    $email = $attributes['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'][0];

    $user = new User();
    $user->activate();
    $user->setIsSamlUser(true);

    // SSO users always sign in through Azure, never with a password. We store
    // 64 random hex characters, which is not a valid password hash, so
    // password_verify() always fails for it and form_login can never sign
    // this account in. Nothing about it can be worked out from the user's details.
    $user->setPassword(bin2hex(random_bytes(32)));
    $user->setEmailAddress($email);

    return $user;
  }
}
