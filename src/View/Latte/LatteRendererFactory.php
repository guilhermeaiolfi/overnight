<?php

namespace ON\View\Latte;

use Psr\Container\ContainerInterface;
use Latte\Engine;
use ON\Container\MiddlewareFactory;
use Mezzio\Application;



class LatteRendererFactory {

  public function __invoke (ContainerInterface $c) {
    $config = $c->get("config");
    $latte = $c->get(Engine::class);
    $app = $c->get(Application::class);

    // lets create a set
    $set = new \Latte\Macros\MacroSet($latte->getCompiler());

    $set->addMacro('section', function ($node, $writer) {
      return $writer->write('echo isset($__sections)? $__sections[%node.args] : null');
    });

    $latte->setTempDirectory($config["paths"]["latte"]);
    $latte->setAutoRefresh($config["latte"]["autorefresh"]);

    $renderer = new \ON\View\Latte\LatteRenderer($config, $latte, $app);
    return $renderer;

  }
}