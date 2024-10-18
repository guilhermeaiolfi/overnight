<?php

namespace ON\Router;

use Psr\Container\ContainerInterface;
use ON\Router\Router;
use ON\RequestStack;

class RouterFactory {

  public function __invoke (ContainerInterface $c) {
    $config = $c->has('config')
    ? $c->get('config')
    : [];

    return new Router(null, null, $config->all(), $c->get(RequestStack::class));
  }
}