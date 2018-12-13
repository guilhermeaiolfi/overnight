<?php

namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Zend\Expressive\Router\RouteResult;
use Zend\Expressive\Router\RouterInterface;
use ON\Action;
use ON\Common\ViewBuilderTrait;
use ON\Container\ExecutorInterface;

use Zend\Expressive\Delegate\NotFoundDelegateInterface;

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
        $action = $request->getAttribute(Action::class);

        if (!$routeResult || !$action) {
            return $handler->process($request);
        }

        $action = $request->getAttribute(Action::class);

        $action_response = $this->executor->execute($action->getExecutable(), [$request, $handler]);

        return $this->buildView($action->getPageInstance(), $action->getActionName(), $action_response, $request, $handler);
    }
}