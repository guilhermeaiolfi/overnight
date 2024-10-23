<?php

namespace ON\Router;

use ON\Config\RouterConfig;
use Psr\Container\ContainerInterface;
use ON\Router\Router;
use ON\RequestStack;

class RouterFactory {

  public function __invoke (ContainerInterface $c) {
    $config = $c->has(RouterConfig::class)
    ? $c->get(RouterConfig::class)
    : [];

    return new Router(null, null, $config, $c->get(RequestStack::class));
  }
}