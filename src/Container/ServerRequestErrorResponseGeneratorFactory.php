<?php

declare(strict_types=1);

namespace ON\Container;

use ON\Config\AppConfig;
use ON\Response\ServerRequestErrorResponseGenerator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Webmozart\Assert\Assert;

class ServerRequestErrorResponseGeneratorFactory
{
    public function __invoke(ContainerInterface $container): ServerRequestErrorResponseGenerator
    {
        //dd(true);
        $config = $container->has(AppConfig::class) ? $container->get(AppConfig::class) : [];
        Assert::isArrayAccessible($config);

        $debug = $config['debug'] ?? false;

        $responseFactory = $container->get(ResponseFactoryInterface::class);

        return new ServerRequestErrorResponseGenerator(
            $responseFactory,
            $debug
        );
    }
}
