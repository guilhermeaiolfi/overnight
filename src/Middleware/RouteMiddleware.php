<?php

namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ON\Router\RouteResult;
use ON\Router\RouterInterface;

use ON\RequestStack;

/**
 * Extends the Default routing middleware because it needs to skip the matcher if
 * there is already a RouteResult set into the request.
 *
 * @internal
 */
class RouteMiddleware implements MiddlewareInterface
{

    public function __construct(
        protected RouterInterface $router, 
        protected RequestStack $stack, 
        protected $container)
    {
    }

    /**
     * @param ServerRequestInterface $request
     * @param DelegateInterface $delegate
     * @return ResponseInterface
     */
    public function process (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $original_request = $request;

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


        $options = $result->getMatchedRoute()->getOptions();
        if (!empty($options) && !empty($options["callbacks"]) && is_array($options["callbacks"])) {
            foreach ($options["callbacks"] as $callback) {
                $callback = $this->container->get($callback);
                $result = $callback->onMatched($result);
            }
        }

        // We need to update the params again, since it may have entered callbacks that changed it
        $request = $request->withAttribute(RouteResult::class, $result);

        foreach ($result->getMatchedParams() as $param => $value) {
            $request = $request->withAttribute($param, $value);
        }


        $this->stack->update($original_request, $request);

        return $handler->handle($request);
    }
}