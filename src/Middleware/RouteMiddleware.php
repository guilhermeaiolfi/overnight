<?php

namespace ON\Middleware;

use Fig\Http\Message\StatusCodeInterface as StatusCode;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Expressive\Router\RouteResult;
use Zend\Expressive\Router\RouterInterface;
use ON\Router\StatefulRouterInterface;
use Zend\Expressive\Middleware\RouteMiddleware as ExpressiveRouteMiddleware;
use Webimpress\HttpMiddlewareCompatibility\HandlerInterface;
use ON\Context;

/**
 * Extends the Default routing middleware because it needs to skip the matcher if
 * there is already a RouteResult set into the request.
 *
 * @internal
 */
class RouteMiddleware extends ExpressiveRouteMiddleware
{
    /**
     * Response prototype for 405 responses.
     *
     * @var ResponseInterface
     */
    private $responsePrototype;

    /**
     * @var RouterInterface
     */
    private $router;

    protected $context;

    protected  $container;

    /**
     * @param RouterInterface $router
     * @param ResponseInterface $responsePrototype
     */
    public function __construct(StatefulRouterInterface $router, ResponseInterface $responsePrototype, Context $context = null, $container = null)
    {
        $this->router = $router;
        $this->responsePrototype = $responsePrototype;
        $this->context = $context;
        $this->container = $container;
    }

    /**
     * @param ServerRequestInterface $request
     * @param DelegateInterface $delegate
     * @return ResponseInterface
     */
    public function process (ServerRequestInterface $request, HandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute(RouteResult::class)) {
            return $handler->process($request);
        }

        $result = $this->router->match($request);

        if ($result->isFailure()) {
            if ($result->isMethodFailure()) {
                return $this->responsePrototype->withStatus(StatusCode::STATUS_METHOD_NOT_ALLOWED)
                    ->withHeader('Allow', implode(',', $result->getAllowedMethods()));
            }
            return $handler->process($request);
        }
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
        $this->router->addRouteResult($result);

        return $handler->process($request);
    }
}