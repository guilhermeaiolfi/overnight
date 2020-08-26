<?php
namespace ON;

use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\Stratigility\MiddlewarePipeInterface;
use Mezzio\Router\RouteCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use ON\Router\StatefulRouterInterface;

use Mezzio\MiddlewareFactory;

class Application extends \Mezzio\Application
{
    /**
     * @var MiddlewareFactory
     */
    protected $factory;
    /**
     * @var MiddlewarePipeInterface
     */
    protected $pipeline;
    /**
     * @var RouteCollector
     */
    protected $routes;
    /**
     * @var RequestHandlerRunner
     */
    protected $runner;

    /**
     * Constructor
     *
     * Calls on the parent constructor, and then uses the provided arguments
     * to set internal properties.
     *
     */
    public function __construct(
        MiddlewareFactory $factory,
        MiddlewarePipeInterface $pipeline,
        RouteCollector $routes,
        RequestHandlerRunner $runner,

        StatefulRouterInterface $router
    ) {
        parent::__construct($factory, $pipeline, $routes, $runner);
        $this->factory        = $factory;
        $this->pipeline        = $pipeline;
        $this->routes          = $routes;
        $this->runner          = $runner;
        $this->router          = $router;
    }

    /**
     * Run the action
     *
     * If no request or response are provided, the method will use
     * ServerRequestFactory::fromGlobals to create a request instance, and
     * instantiate a default response instance.
     *
     * It retrieves the default delegate using getDefaultRequestHandler(), and
     * uses that to process itself.
     *
     * Once it has processed itself, it emits the returned response using the
     * composed emitter.
     *
     * @param null|ServerRequestInterface $request
     * @param null|ResponseInterface $response
     * @return void
     */
    public function runAction(ServerRequestInterface $request = null, ResponseInterface $response = null)
    {
        $response = $response ?: new Response();
        $request  = $request->withAttribute('originalResponse', $response);

        return $this->handle($request);
    }
}