<?php

namespace ON\Middleware;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Zend\Expressive\Router\RouteResult;
use Zend\Expressive\Router\RouterInterface;
use ON\Action;

class ActionInjectionMiddleware implements MiddlewareInterface
{
    protected $container = null;

    public function __construct(ContainerInterface $container) {
        $this->container = $container;
    }

    public function process (ServerRequestInterface $request,  RequestHandlerInterface $handler): ResponseInterface
    {
        $routeResult = $request->getAttribute(RouteResult::class, false);

        if (! $routeResult) {
            return $handler->handle($request, $handler);
        }

        $middleware = $routeResult->getMatchedRoute()->getMiddleware();

        $action = null;

        $action = new Action($middleware->getString());

        $instance = $this->container->get($action->getClassName());

        $action->setPageInstance($instance);

        $request = $request->withAttribute(Action::class, $action);

        return $handler->handle($request, $handler);
    }
}