<?php

namespace ON\View\Plates;

use Psr\Container\ContainerInterface;

class PlatesRendererFactory {

  public function __invoke (ContainerInterface $c) {
    // Attention! engine folders are added in the EngineFactory
    
    $renderer = $c->get(\ON\View\Plates\PlatesRenderer::class);
    return $renderer;

  }
}