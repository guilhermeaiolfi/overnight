<?php

namespace ON\Middleware;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Mezzio\Router\RouteResult;
use Mezzio\Router\RouterInterface;
use ON\Action;
use ON\RequestStack;

class RegisterRequestMiddleware implements MiddlewareInterface
{
    public function __construct(protected RequestStack $stack)
    {
    }

    public function process (ServerRequestInterface $request,  RequestHandlerInterface $handler): ResponseInterface
    {
        $this->stack->push($request);
        $response = $handler->handle($request);
        $this->stack->pop();
        return $response;
    }
}