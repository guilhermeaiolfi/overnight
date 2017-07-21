<?php

namespace ON\Middleware;
use Interop\Http\ServerMiddleware\MiddlewareInterface as ServerMiddlewareInterface;
use Interop\Http\ServerMiddleware\DelegateInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Zend\Expressive\Router\RouteResult;
use Zend\Expressive\Router\RouterInterface;
use ON\Action;

class ActionInjectionMiddleware implements ServerMiddlewareInterface
{
    protected $container = null;

    public function __construct(ContainerInterface $container) {
        $this->container = $container;
    }

    public function process (ServerRequestInterface $request, DelegateInterface $delegate)
    {
        $routeResult = $request->getAttribute(RouteResult::class, false);

        if (! $routeResult) {
            return $delegate->process($request);
        }

        $middleware = $routeResult->getMatchedMiddleware();

        $action = new Action($middleware);

        $instance = $this->container->get($action->getClassName());

        $action->setPageInstance($instance);

        $request = $request->withAttribute(Action::class, $action);

        return $delegate->process($request);
    }
}
?>