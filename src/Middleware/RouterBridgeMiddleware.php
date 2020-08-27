<?php

declare(strict_types=1);

namespace ON\Middleware;

use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ON\Router\RouterBridge;
use ON\Context;
use Psr\Container\ContainerInterface;

/**
 * Pipeline middleware for injecting a UrlHelper with a RouteResult.
 */
class RouterBridgeMiddleware implements MiddlewareInterface
{
    /**
     * @var RouterBridge
     */
    private $bridge;
    private $context;

    public function __construct(RouterBridge $bridge, Context $context)
    {
        $this->bridge = $bridge;
        $this->context = $context;
    }

    /**
     * Inject the RouterBridge instance with a RouteResult, if present as a request attribute.
     * Injects the bridge, and then dispatches the next middleware.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
    {
        $result = $request->getAttribute(RouteResult::class, false);
        $this->context->setAttribute("REQUEST", $request);
        $this->bridge->setContext($this->context);

        if ($result instanceof RouteResult) {
            //$this->context->setAttribute(RouteResult::class, $result);
            $this->bridge->addRouteResult($result);
        }


        return $handler->handle($request);
    }
}
