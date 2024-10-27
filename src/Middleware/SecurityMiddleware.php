<?php

namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ON\Router\RouteResult;
use ON\Router\ActionMiddlewareDecorator;

class SecurityMiddleware implements MiddlewareInterface
{
    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeResult = $request->getAttribute(RouteResult::class, false);

        if (!$routeResult) {
            return $handler->handle($request);
        }

         
        $route = $routeResult->getMatchedRoute();
        $middleware = $route->getMiddleware();
        
        if ($middleware instanceof ActionMiddlewareDecorator) {
            return $middleware->loggedCheck($request, $handler);
        }

        return $handler->handle($request);
    }
}