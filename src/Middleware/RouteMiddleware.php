<?php

namespace ON\Middleware;

use Fig\Http\Message\StatusCodeInterface as StatusCode;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Mezzio\Router\RouteResult;
use Mezzio\Router\RouterInterface;
use Mezzio\Router\Middleware\RouteMiddleware as MezzioRouteMiddleware;


use ON\Context;

/**
 * Extends the Default routing middleware because it needs to skip the matcher if
 * there is already a RouteResult set into the request.
 *
 * @internal
 */
class RouteMiddleware extends MezzioRouteMiddleware
{
    /**
     * @var RouterInterface
     */
    protected $router;

    protected $context;

    protected  $container;

    /**
     * @param RouterInterface $router
     * @param ResponseInterface $responsePrototype
     */
    public function __construct(RouterInterface $router, Context $context = null, $container = null)
    {
        $this->router = $router;
        $this->context = $context;
        $this->container = $container;
    }

    /**
     * @param ServerRequestInterface $request
     * @param DelegateInterface $delegate
     * @return ResponseInterface
     */
    public function process (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute(RouteResult::class)) {
            return $handler->handle($request);
        }

        $result = $this->router->match($request);

        if ($result->isFailure()) {
            return $handler->handle($request);
        }

        // Inject the actual route result, as well as individual matched parameters.
        $request = $request->withAttribute(RouteResult::class, $result);

        foreach ($result->getMatchedParams() as $param => $value) {
            $request = $request->withAttribute($param, $value);
        }

        $this->context->setAttribute(RouteResult::class, $result);
        $this->context->setAttribute("REQUEST", $request);
        //$this->router->addRouteResult($result);

        $options = $result->getMatchedRoute()->getOptions();
        if (!empty($options) && !empty($options["callbacks"]) && is_array($options["callbacks"])) {
            foreach ($options["callbacks"] as $callback) {
                $callback = $this->container->get($callback);
                $result = $callback->onMatched($result);
            }
        }

        // Inject the actual route result, as well as individual matched parameters.
        $request = $request->withAttribute(RouteResult::class, $result);

        foreach ($result->getMatchedParams() as $param => $value) {
            $request = $request->withAttribute($param, $value);
        }

        $this->context->setAttribute(RouteResult::class, $result);
        $this->context->setAttribute("REQUEST", $request);
        //$this->router->addRouteResult($result);

        return $handler->handle($request);
    }
}