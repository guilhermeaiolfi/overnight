<?php

namespace ON\Auth;

use Psr\Container\ContainerInterface;
use ON\Auth\AuthorizationServiceInterface;
use ON\Auth\Exception\NotImplementedException;

class AuthorizationService implements AuthorizationServiceInterface
{
  protected $acl = null;
  public function __construct (ContainerInterface $container) {

    throw new NotImplementedException("This service handle permissions and should be implemented in your application. It should be linked to ON\Auth\AuthorizationServiceInterface in your container too.");
  }
}
