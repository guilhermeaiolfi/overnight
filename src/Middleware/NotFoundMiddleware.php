<?php
namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Container\ContainerInterface;
use ON\Container\ExecutorInterface;
use ON\Exception\NotFoundException;


class NotFoundMiddleware implements MiddlewareInterface
{
    /**
     * @var ContainerInterface|null
     */
    protected $container;

    /**
     * @param ContainerInterface|null $container
     */
    public function __construct(ContainerInterface $container) {
        $this->container = $container;
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

            $notFoundHandler = $this->container->get(\Zend\Expressive\Handler\NotFoundHandler::class);
            return $notFoundHandler->handle($request, $handler);
        }
    }
}