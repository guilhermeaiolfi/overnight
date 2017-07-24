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
use ON\Common\ViewBuilderTrait;
use ON\Container\ExecutorInterface;

class ExecutionMiddleware implements ServerMiddlewareInterface
{
    use ViewBuilderTrait;

    protected $container = null;
    protected $executor = null;

    public function __construct(ContainerInterface $container, ExecutorInterface $executor) {
        $this->container = $container;
        $this->executor  = $executor;
    }

    public function process (ServerRequestInterface $request, DelegateInterface $delegate)
    {
        $routeResult = $request->getAttribute(RouteResult::class, false);
        $action = $request->getAttribute(Action::class);

        if (!$routeResult || !$action) {
            return $delegate->process($request);
        }

        $action = $request->getAttribute(Action::class);

        $action_response = $this->executor->execute($action->getExecutable(), [$request, $delegate]);

        return $this->buildView($action->getPageInstance(), $action_response, $request, $delegate);
    }
}
?>