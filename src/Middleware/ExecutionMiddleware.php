<?php

namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use ON\Router\RouteResult;
use ON\Action;
use ON\Common\ViewBuilderTrait;
use ON\Container\Executor\ExecutorInterface;

class ExecutionMiddleware implements MiddlewareInterface
{
    use ViewBuilderTrait;

    protected $container = null;
    protected $executor = null;

    public function __construct(ContainerInterface $container, ExecutorInterface $executor) {
        $this->container = $container;
        $this->executor  = $executor;
    }

    public function process (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeResult = $request->getAttribute(RouteResult::class, false);
        if (!$routeResult) {

            return $handler->handle($request);
        }
  
        $middleware = $routeResult->getMatchedRoute()->getMiddleware();

        return $middleware->process($request, $handler);
    }
}