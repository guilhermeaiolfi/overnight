<?php

namespace ON\Router;

use Psr\Container\ContainerInterface;
use Aura\Router\RouterContainer;
use Mezzio\Router\AuraRouter;
use ON\Router\Router;
use ON\Context;
use ON\Router\RouterBridge;
use Mezzio\Router\RouterInterface;

class RouterBridgeFactory {

  public function __invoke (ContainerInterface $c) {
    $config = $c->get("config");
    $basepath = RouterBridge::getBaseHref($config);
    $router = $c->get(RouterInterface::class);
    return new RouterBridge($router, $basepath);

  }
}