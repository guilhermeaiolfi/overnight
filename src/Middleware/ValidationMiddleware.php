<?php

namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Mezzio\Router\RouteResult;
use Mezzio\Router\RouterInterface;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Response\EmptyResponse;
use ON\Container\ExecutorInterface;
use ON\Exception\SecurityException;
use ON\User\UserInterface;
use ON\Action;
use ON\Common\ViewBuilderTrait;

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
        $action = $request->getAttribute(Action::class, false);

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

        $args = [$request];
        $result = $this->executor->execute([$page, $validateMethod], $args);

        if ($result) {
            return $handler->handle($request, $handler);
        }

        //if it's not validated, we need to handle the error response
        $handleErrorMethod = "handleError";
        if (!method_exists($page, $handleErrorMethod)) {
            $handleErrorMethod = "defaultHandleError";
        }
        $response = $this->executor->execute([$page, $handleErrorMethod], $args);

        return $this->buildView($page, $action->getActionName(), $response, $request, $handler);
    }
}