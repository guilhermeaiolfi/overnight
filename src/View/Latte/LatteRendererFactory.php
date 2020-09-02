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

    var_dump($config["latte"]);exit;

    $latte->setTempDirectory($config["paths"]["latte"]);
    $latte->setAutoRefresh($config["latte"]["autorefresh"]);

    $renderer = new \ON\View\Latte\LatteRenderer($config, $latte, $app);
    return $renderer;

  }
}