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
use ON\Middleware\OutputTypeMiddleware;
use DebugBar\JavascriptRenderer as DebugBarRenderer;
use ON\RequestStack;

/**
 * PhpDebugBarMiddleware
 *
 */
class PhpDebugBarMiddleware implements MiddlewareInterface
{
    private $debugbarRenderer;
    private $responseFactory;
    private $streamFactory;
    private RequestStack $stack;
    private $parent;

    public function __construct(
        DebugBarRenderer $debugbarRenderer,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        ContainerInterface $container,
        RequestStack $stack
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
        if (!$this->stack->isMainRequest($request) || $request->getAttribute(OutputTypeMiddleware::class) != "html") {
            return $handler->handle($request);
        }
        return $this->parent->process($request, $handler);
    }
}