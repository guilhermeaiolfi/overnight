<?php

namespace ON\Router;

use Psr\Container\ContainerInterface;
use Aura\Router\RouterContainer;
use Mezzio\Router\AuraRouter;
use ON\Router\RouterBridge;
use ON\Context;

class RouterFactory {

  protected $container;

  public function __construct (ContainerInterface $c) {
    $this->container = $c;
  }

  public function __invoke () {
    $config = $this->container->get("config");
    $basepath = RouterBridge::getBaseHref($config);
    $aura = new RouterContainer($basepath);

    $router = new AuraRouter($aura);
    return $router;
  }
}