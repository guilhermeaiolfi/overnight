<?php

namespace ON\Middleware;

use ON\Action;
use ON\Application;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ON\Router\RouteResult;
use ON\Router\RouterInterface;

use ON\RequestStack;

/**
 * Extends the Default routing middleware because it needs to skip the matcher if
 * there is already a RouteResult set into the request.
 *
 * @internal
 */
class RouteMiddleware implements MiddlewareInterface
{

    public function __construct(
        protected RouterInterface $router, 
        protected RequestStack $stack, 
        protected Application $app
    )
    {
    }

    /**
     * @param ServerRequestInterface $request
     * @param DelegateInterface $delegate
     * @return ResponseInterface
     */
    public function process (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $original_request = $request;

        if ($request->getAttribute(RouteResult::class)) {
            return $handler->handle($request);
        }
        
        $result = $this->router->match($request);
        if ($result->isFailure()) {
            return $handler->handle($request);
        }

        $request = $this->app->ext('pipeline')->prepareRequestFromRouteResult($result, $request);

        return $handler->handle($request);
    }
}