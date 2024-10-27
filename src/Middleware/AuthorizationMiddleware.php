<?php

namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ON\Router\RouterInterface;
use ON\Auth\AuthenticationServiceInterface;
use ON\Auth\AuthorizationServiceInterface;
use ON\Container\Executor\ExecutorInterface;
use ON\Exception\SecurityException;
use ON\Action;
use ON\Router\ActionMiddlewareDecorator;
use ON\Router\RouteResult;

class AuthorizationMiddleware implements MiddlewareInterface
{
   /**
   * @param ServerRequestInterface $request
   * @param RequestHandlerInterface $handler
   * @return ResponseInterface
   */
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $routeResult = $request->getAttribute(RouteResult::class, false);
    if (!$routeResult) {
      return $handler->handle($request, $handler);
    }
    
    $route = $routeResult->getMatchedRoute();
    $middleware = $route->getMiddleware();
    
    if ($middleware instanceof ActionMiddlewareDecorator) {
        return $middleware->loggedCheck($request, $handler);
    }

    return $handler->handle($request, $handler);
  }
}