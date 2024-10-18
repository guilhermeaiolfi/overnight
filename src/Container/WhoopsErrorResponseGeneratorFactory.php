<?php

declare(strict_types=1);

namespace ON\Container;

use ON\Config\AppConfig;
use ON\Middleware\WhoopsErrorResponseGenerator;
use Psr\Container\ContainerInterface;

class WhoopsErrorResponseGeneratorFactory
{
    public function __invoke(ContainerInterface $container): WhoopsErrorResponseGenerator
    {
        /*$cfg = $container->get(AppConfig::class);
        $whoops = $cfg->get("whoops");*/
        return new WhoopsErrorResponseGenerator(
            $container->get("ON\Whoops")
        );
    }
}
