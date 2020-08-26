<?php

namespace ON\Router;

use Psr\Container\ContainerInterface;
use Aura\Router\RouterContainer;
use Mezzio\Router\AuraRouter;
use ON\Router\Router;
use ON\Context;

class RouterFactory {

  public function __invoke (ContainerInterface $c) {
    $config = $c->get('config');
    $context = $c->get(Context::class);
    $basepath = $config["paths"]["basepath"];
    $basepath = isset($basepath) && $basepath != null? $basepath : Router::detectBaseUrl();
    $aura = new RouterContainer($basepath);

    $router = new AuraRouter($aura);
    $router = new Router($router, $basepath, $context);
    return $router;
  }
}