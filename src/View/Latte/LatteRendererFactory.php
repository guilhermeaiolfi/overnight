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
      //return $writer->write('echo isset($__sections)? $__sections[%node.args] : null');
      return $writer->write('
        $_blockName = "' . $node->args . '";' .
        'if (isset($__sections) && isset($__sections[$_blockName]) && is_array($__sections[$_blockName])) {
          if ($__sections[$_blockName]["type"] == "text") {
            echo isset($__sections[$_blockName]["content"])? $__sections[$_blockName]["content"] : null;
          } else if ($__sections[$_blockName]["type"] == "file") {
			      $this->createTemplate($__sections[$_blockName]["content"], %node.array? + get_defined_vars(), "include")->render();
          }
        }', implode($node->context));
    });

    $latte->setTempDirectory($config["paths"]["latte"]);
    $latte->setAutoRefresh($config["latte"]["autorefresh"]);

    $renderer = new \ON\View\Latte\LatteRenderer($config, $latte, $app, $c);
    return $renderer;

  }
}