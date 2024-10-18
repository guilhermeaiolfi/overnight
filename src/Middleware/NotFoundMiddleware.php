<?php
namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Container\ContainerInterface;
use ON\Container\ExecutorInterface;
use ON\Exception\NotFoundException;
use ON\Handler\NotFoundHandler;

class NotFoundMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected NotFoundHandler $notFoundHandler
    ) {
    }

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request, $handler);
            return $response;
        } catch (NotFoundException $e) {
            return $this->notFoundHandler->handle($request, $handler);
        }
    }
}