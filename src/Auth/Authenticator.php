<?php
namespace ON\Auth;

use ON\Auth\AuthenticatorInterface;
use ON\Auth\Exception\NotImplementedException;

class Authenticator implements AuthenticatorInterface
{
    public function authenticate()
    {
       throw new NotImplementedException("You should implement your own adapter and link it to ON\Auth\AuthenticatorInterface in your container.");
    }
}