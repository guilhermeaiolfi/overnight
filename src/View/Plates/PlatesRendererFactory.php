<?php

namespace ON\View\Plates;

use League\Plates\Engine;
use Psr\Container\ContainerInterface;
use Mezzio\Plates\PlatesRenderer as MezzioPlatesRenderer;
use ON\Container\MiddlewareFactory;
use ON\Application;

class PlatesRendererFactory {

  public function __invoke (ContainerInterface $c) {
    $config = $c->get("config");
    $engine = $c->get(Engine::class);
//    $renderer = $c->get(MezzioPlatesRenderer::class);
    $middleware_factory = $c->get(MiddlewareFactory::class);
    $app = $c->get(Application::class);

    // Attention! engine folders are added in the EngineFactory
    
    $renderer = new \ON\View\Plates\PlatesRenderer($config, $engine, $app);
    return $renderer;

  }
}