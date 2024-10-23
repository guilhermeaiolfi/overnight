<?php
namespace ON\Router;

use ON\Action;
use ON\Common\ViewBuilderTrait;
use ON\Container\Executor\ExecutorInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

class ActionMiddlewareDecorator implements MiddlewareInterface
{
    use ViewBuilderTrait;
    protected mixed $instance = null;

    protected ?string $className = null;
    protected string $method = "index";
    public function __construct(
        protected ContainerInterface $container,
        public readonly string $middlewareName,
        
    )
    {
        if (is_string($middlewareName) && strpos($middlewareName, "::") !== FALSE) {

            [$className, $method] = explode("::", $middlewareName);

            $this->className = $className;
            $this->method = $method;

            $this->instance = $this->container->get($className);
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->execute($request, $handler);
    }

    public function execute(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {

        $executor = $this->container->get(ExecutorInterface::class);
        $args = [
            ServerRequestInterface::class => $request,
            RequestHandlerInterface::class => $handler
        ];
        $action_response = $executor->execute([$this->className, $this->method], $args);

        return $this->buildView($this->instance, $this->method, $action_response, $request, $handler);

    }

    public function getClassName(): string
    {
        return $this->className;
    }
}