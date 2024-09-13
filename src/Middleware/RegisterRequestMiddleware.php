<?php

namespace ON\Middleware;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use ON\Application;
use ON\Event\NamedEvent;
use ON\RequestStack;

class RegisterRequestMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected Application $app,
        protected RequestStack $stack)
    {
    }

    public function process (ServerRequestInterface $request,  RequestHandlerInterface $handler): ResponseInterface
    {
        $this->stack->push($request);
        if ($this->app->hasExtension('events')) {
            $this->app->ext('events')->dispatch(new NamedEvent('core.request', $request));
        }
        $response = $handler->handle($request);
        $this->stack->pop();
        return $response;
    }
}