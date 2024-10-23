<?php

namespace ON\Middleware;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use ON\Router\RouteResult;
use ON\Action;
use ON\Application;
use ON\Extension\PipelineExtension;
use ON\RequestStack;

class ActionInjectionMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected Application $app,
        protected ContainerInterface $container,
        protected RequestStack $stack
    ) {
    }

    public function process (ServerRequestInterface $request,  RequestHandlerInterface $handler): ResponseInterface
    {
        $routeResult = $request->getAttribute(RouteResult::class, false);

        if (!$routeResult) {
            return $handler->handle($request, $handler);
        }

        $middleware = $routeResult->getMatchedRoute()->getMiddleware();
        if (is_string($middleware)) {
            $middleware = $this->app->ext('pipeline')->prepareMiddleware($middleware);
            $routeResult->getMatchedRoute()->setMiddleware($middleware);
        }

        $action = null;

        if ($middleware instanceof \ON\Router\ActionMiddlewareDecorator && strpos($middleware->middlewareName, "::") !== FALSE) {
            $action = new Action($middleware->middlewareName);

            $instance = $this->container->get($action->getClassName());

            $action->setPageInstance($instance);

            /*$old_request = $request;

            $request = $request->withAttribute(Action::class, $action);

            $this->stack->update($old_request, $request);*/

            $routeResult->set(Action::class, $action);
        }

        return $handler->handle($request, $handler);
    }
}