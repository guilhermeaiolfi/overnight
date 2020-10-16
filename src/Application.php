<?php
namespace ON;

use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\Stratigility\MiddlewarePipeInterface;
use Mezzio\Router\RouteCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
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
        if (is_string($controller)) { //middleware
            $controller = $this->factory->prepare($controller);
        }

        $route = new Route($path, $controller, $methods, $route_name);
        $route_result = RouteResult::fromRoute($route);

        $request = $request->withAttribute(RouteResult::class, $route_result);

        $request = $request->withAttribute("PARENT-REQUEST", $request);

        if (!isset($section_config["renderer"])) {
            $section_config["renderer"] = PlatesRenderer::class;
        }
        return $request;

    }

    public function runAction ($request, $response = null) {
        $response = $response ?: new Response();
        $request  = $request->withAttribute('originalResponse', $response);
        return $this->handle($request);
    }
}