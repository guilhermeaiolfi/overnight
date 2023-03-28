<?php
namespace ON\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Mezzio\Router\RouteResult;
use ON\Action;

class ActionMiddlewareDecorator implements MiddlewareInterface
{
    /**
     * @var callable
     */
    private $middleware;

    public function __construct($middleware)
    {
        $this->middleware = $middleware;
    }

    public function getString() {
        return $this->middleware;
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
        $action_response = $this->executor->execute($action->getExecutable(), [$request, $handler]);

        return $this->buildView($action->getPageInstance(), $action->getActionName(), $action_response, $request, $handler);
        //return $middleware = $this->middleware;
    }
}