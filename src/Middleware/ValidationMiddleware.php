<?php

namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ON\Container\Executor\ExecutorInterface;
use ON\Action;
use ON\Common\ViewBuilderTrait;
use ON\Router\RouteResult;

class ValidationMiddleware implements MiddlewareInterface
{
    use ViewBuilderTrait;
    /**
     * @var ContainerInterface|null
     */
    protected $container;

    protected $executor;

    /**
     * @param ContainerInterface|null $container
     */
    public function __construct(
        ContainerInterface $container = null,
        ExecutorInterface $executor
    ) {
        $this->container = $container;
        $this->executor = $executor;
    }

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
        
        $action = $routeResult->get(Action::class);

        if (!$action) {
            return $handler->handle($request, $handler);
        }

        $page = $action->getPageInstance();


        $validateMethod = $action->getActionName() . 'Validate';
        if(!method_exists($page, $validateMethod)) {
            $validateMethod = 'validate';
        }
        if (!method_exists($page, $validateMethod)) {
            $validateMethod = 'defaultValidate';
        }

        if (method_exists($page, $validateMethod)) {
            $args = [
                ServerRequestInterface::class => $request
            ];
            $result = $this->executor->execute([$page, $validateMethod], $args);

            if ($result) {
                return $handler->handle($request, $handler);
            }
            // if it's not validated, we need to handle the error response
            $handleErrorMethod = "handleError";
            if (!method_exists($page, $handleErrorMethod)) {
                $handleErrorMethod = "defaultHandleError";
            }
            $response = $this->executor->execute([$page, $handleErrorMethod], $args);

            return $this->buildView($page, $action->getActionName(), $response, $request, $handler);
        }
        return $handler->handle($request, $handler);
    }
}