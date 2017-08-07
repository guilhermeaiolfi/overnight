<?php

namespace ON\Middleware;

use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface as ServerMiddlewareInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Expressive\Router\RouteResult;
use Zend\Expressive\Router\RouterInterface;
use Zend\Diactoros\Response\RedirectResponse;
use Zend\Diactoros\Response\EmptyResponse;
use ON\Container\ExecutorInterface;
use ON\Exception\SecurityException;
use ON\User\UserInterface;
use ON\Action;
use ON\Common\ViewBuilderTrait;

class ValidationMiddleware implements ServerMiddlewareInterface
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
     * @param DelegateInterface $delegate
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, DelegateInterface $delegate)
    {
        $action = $request->getAttribute(Action::class, false);

        if (!$action) {
            return $delegate->process($request, $delegate);
        }

        $page = $action->getPageInstance();


        $validateMethod = $action->getActionName() . 'Validate';
        if(!method_exists($page, $validateMethod)) {
            $validateMethod = 'validate';
        }

        $args = [$request];
        $result = $this->executor->execute([$page, $validateMethod], $args);

        if ($result) {
            return $delegate->process($request, $delegate);
        }

        //if it's not validated, we need to handle the error response
        $handlerErrorMethod = "handleError";
        $response = $this->executor->execute([$page, $handlerErrorMethod], $args);
        return $this->buildView($page, $action->getActionName(), $response, $request, $delegate);
    }
}
