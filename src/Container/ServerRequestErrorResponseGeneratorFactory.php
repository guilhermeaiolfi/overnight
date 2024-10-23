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
        $debug = !!$_ENV["APP_DEBUG"];

        $responseFactory = $container->get(ResponseFactoryInterface::class);

        return new ServerRequestErrorResponseGenerator(
            $responseFactory,
            $debug
        );
    }
}
