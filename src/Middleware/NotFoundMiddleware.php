<?php
namespace ON\Middleware;

use Interop\Http\ServerMiddleware\MiddlewareInterface as ServerMiddlewareInterface;
use Interop\Http\ServerMiddleware\DelegateInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use ON\Container\ExecutorInterface;
use Zend\Expressive\Delegate\NotFoundDelegateInterface;
use ON\Exception\NotFoundException;


class NotFoundMiddleware implements ServerMiddlewareInterface
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
     * @param DelegateInterface $delegate
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, DelegateInterface $delegate)
    {
        try {
            $response = $delegate->process($request);
            return $response;
        } catch (NotFoundException $e) {
            $notFoundDelegate = $this->container->get(\Zend\Expressive\Delegate\NotFoundDelegate::class);
            return $notFoundDelegate->process($request);
        }
    }
}