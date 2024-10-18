<?php

declare(strict_types=1);

namespace ON\Container;

use ON\Middleware\ErrorResponseGenerator;
use ON\Config\AppConfig;
use Psr\Container\ContainerInterface;
use Webmozart\Assert\Assert;

use function array_key_exists;

class ErrorResponseGeneratorFactory
{
    public function __invoke(ContainerInterface $container): ErrorResponseGenerator
    {
        /*$config = $container->has(AppConfig::class) ? $container->get(AppConfig::class) : [];
        Assert::isArrayAccessible($config);*/

        $debug               = $_ENV["APP_DEBUG"] ?? false;
        /*$mezzioConfiguration = $config['mezzio'] ?? [];
        Assert::isMap($mezzioConfiguration);*/

        return new ErrorResponseGenerator($debug);
    }
}
