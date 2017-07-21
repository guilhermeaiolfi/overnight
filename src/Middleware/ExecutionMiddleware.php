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
use ON\Container\ExecutorInterface;

class ExecutionMiddleware implements ServerMiddlewareInterface
{
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
        if (is_string($action_response)) {
            $view_method = $action_response;
            $view = $action->getPageInstance();
            if (strpos($view_method, ":") !== FALSE) {
              $view_method = explode(":", $view_method);
              $view = $this->container->get($view_method[0] . 'Page');
              $view->setAttributesByRef($action->getPageInstance()->getAttributes());
              $view_method = $view_method[1];
            }
            $view_method = strtolower($view_method);
            return $view->{$view_method . 'View'}($request, $delegate);
        }
        return $action_response;
    }
}
?>