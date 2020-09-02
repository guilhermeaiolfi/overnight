<?php

namespace ON\View\Plates;

use Psr\Container\ContainerInterface;
use Mezzio\Plates\PlatesRenderer as MezzioPlatesRenderer;
use ON\Container\MiddlewareFactory;
use Mezzio\Application;

class PlatesRendererFactory {

  public function __invoke (ContainerInterface $c) {
    $config = $c->get("config");
    $engine = $c->get(\League\Plates\Engine::class);
    $renderer = $c->get(MezzioPlatesRenderer::class);
    $middleware_factory = $c->get(MiddlewareFactory::class);
    $app = $c->get(Application::class);
    $renderer = new \ON\View\Plates\PlatesRenderer($config, $engine, $app);
    return $renderer;

  }
}