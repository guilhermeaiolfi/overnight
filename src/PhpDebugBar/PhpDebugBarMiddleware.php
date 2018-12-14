<?php

namespace ON\PhpDebugBar;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Container\ContainerInterface;
use PhpMiddleware\PhpDebugBar\PhpDebugBarMiddleware as DebugBarMiddleware;

use DebugBar\JavascriptRenderer as DebugBarRenderer;

/**
 * PhpDebugBarMiddleware
 *
 */
class PhpDebugBarMiddleware implements MiddlewareInterface
{
    private $debugbarRenderer;
    private $responseFactory;
    private $streamFactory;
    private $parent;

    public function __construct(
        DebugBarRenderer $debugbarRenderer,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        ContainerInterface $container
    ) {
        $this->debugbarRenderer = $debugbarRenderer;

        $config = $container->get('config');
        $rendererOptions = $config['phpmiddleware']['phpdebugbar']['javascript_renderer'];

        $debugbarRenderer->setOptions($rendererOptions);

        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->parent = new DebugBarMiddleware($debugbarRenderer, $responseFactory, $streamFactory);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute("PARENT-REQUEST") || $request->getAttribute("OUTPUT_TYPE") != "html") {
            return $handler->handle($request);
        }
        return $this->parent->process($request, $handler);
    }
}