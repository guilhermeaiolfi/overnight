<?php

declare(strict_types=1);

namespace ON\Container;

use ON\Handler\NotFoundHandler;
use ON\Application;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

use function array_key_exists;

class NotFoundHandlerFactory
{
    public function __invoke(ContainerInterface $container) : NotFoundHandler
    {
        $config   = $container->has('config') ? $container->get('config') : [];
        $app = $container->get(Application::class);

        $errorHandlerConfig = $config['errors']['404'] ?? [];

        return new NotFoundHandler($app, $errorHandlerConfig, $container->get(ResponseInterface::class));
    }
}
