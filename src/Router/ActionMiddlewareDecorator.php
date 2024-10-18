<?php
namespace ON\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use ON\Router\RouteResult;
use ON\Action;

class ActionMiddlewareDecorator implements MiddlewareInterface
{
    public function __construct(public readonly string $middlewareName)
    {
    }

    /**
     * {@inheritDoc}
     * @throws Exception\MissingResponseException if the decorated middleware
     *     fails to produce a response.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeResult = $request->getAttribute(RouteResult::class, false);

        $action = $request->getAttribute(Action::class);

        if (!$routeResult || !$action) {
            return $handler->handle($request);
        }

        $action = $request->getAttribute(Action::class);

        return $handler->handle($request);
    }
}