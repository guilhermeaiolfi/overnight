<?php
namespace ON;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Container\ContainerInterface;

use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\Stratigility\MiddlewarePipeInterface;
use Mezzio\Router\RouteCollector;
use Mezzio\MiddlewareFactory;
use Mezzio\Router\RouteResult;
use Mezzio\Router\Route;

use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Request;


class Application extends \Mezzio\Application {

    /**
     * @var MiddlewareFactory
     */
    public $factory;

    /** @var ContainerInterface */
    private static $container;

    /**
     * Returns currently active container scope if any.
     *
     * @return null|ContainerInterface
     */
    public static function getContainer(): ?ContainerInterface
    {
        return self::$container;
    }

    public static function setContainer($container) {
        self::$container = $container;
    }

    public function __construct(
        MiddlewareFactory $factory,
        MiddlewarePipeInterface $pipeline,
        RouteCollector $routes,
        RequestHandlerRunner $runner
    ) {
        parent::__construct($factory, $pipeline, $routes, $runner);
        $this->factory = $factory;
    }

    public function prepareRequest($path, $controller, $methods, $route_name) {
        $request = ServerRequestFactory::fromGlobals();

        //$request = $request->withAttribute("PARENT-REQUEST", $request);

        if (is_string($controller)) { //middleware
            $controller = $this->factory->prepare($controller);
        }

        $route = new Route($path, $controller, $methods, $route_name);
        $route_result = RouteResult::fromRoute($route);

        $request = $request->withAttribute(RouteResult::class, $route_result);

        return $request;
    }

    public function runAction ($request, $response = null) {
        $response = $response ?: new Response();
        $request  = $request->withAttribute('originalResponse', $response);
        return $this->handle($request);
    }
}