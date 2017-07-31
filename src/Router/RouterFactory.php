<?php

namespace ON\Router;

use Psr\Container\ContainerInterface;
use Aura\Router\RouterContainer;
use Zend\Expressive\Router\AuraRouter;
use ON\Router\Router;
use ON\Context;

class RouterFactory {

  protected $container;

  public function __construct (ContainerInterface $c) {
    $this->container = $c;
  }

  public function __invoke () {
    $config = $this->container->get('config');
    $context = $this->container->get(Context::class);
    $basepath = $config["paths"]["basepath"];
    $aura = new RouterContainer($basepath);

    $router = new AuraRouter($aura);
    $router = new Router ($router, $basepath, $context);
    return $router;
  }
}
