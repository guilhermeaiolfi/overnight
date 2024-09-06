<?php
namespace ON\Middleware;

use Clockwork\Support\Vanilla\Clockwork;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use ON\RequestStack;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

// Generic Clockwork middleware for use with PSR-compatible applications
class ClockworkMiddleware implements MiddlewareInterface
{

    // The underlying vanilla Clockwork support class instance
    protected $clockwork;

    // Whether this middleware should handle routing for Clockwork REST api and Web interface
    protected $handleRouting = true;
    
    // PSR-17 request factory instance
    protected $responseFactory;
    
    // Creates new middleware instance, takes a vanilla Clockwork support class instance as an argument
    public function __construct(
        Clockwork $clockwork,
        private RequestStack $stack
        //ResponseFactoryInterface $responseFactory
    )
    {
        $this->clockwork = $clockwork;
        //$this->responseFactory = $responseFactory;
    }
    
    // Returns a new middleware instance with a default singleton Clockwork instance, takes an additional configuration as argument
    public static function init($config = [])
    {
        return new static(Clockwork::init($config));
    }
    
    // Sets a PSR-17 compatible response factory. When using the middleware with routing enabled, response factory must be manually set
    // or the php-http/discovery has to be intalled for zero-configuration use
    public function withResponseFactory(ResponseFactoryInterface $responseFactory)
    {
        $this->responseFactory = $responseFactory;
        return $this;
    }
    
    // Disables routing handling in the middleware. When disabled additional manual configuration of the application router is required.
    public function withoutRouting()
    {
        $this->handleRouting = false;
        return $this;
    }
    
    // Process the middleware
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) :ResponseInterface
    {
        $original_request = $request;

        $request = $request->withAttribute('clockwork', $this->clockwork);

        $this->stack->update($original_request, $request);
        
        $this->clockwork->event('Controller')->begin();

        if ($response = $this->handleApiRequest($request)) return $response;
        if ($response = $this->handleWebRequest($request)) return $response;

        $response = $handler->handle($request);
        
        if ($this->stack->isMainRequest($request)) {
            return $this->clockwork->usePsrMessage($request, $response)->requestProcessed();
        }
        return $response;
    }

    // Handle a Clockwork REST api request if routing is enabled
    protected function handleApiRequest(ServerRequestInterface $request)
    {
        $path = $this->clockwork->getConfig()['api'];
        if (! $this->handleRouting) return;
        if (! preg_match("#{$path}.*#", $request->getUri()->getPath())) return;
        return $this->clockwork->usePsrMessage($request, $this->prepareResponse())->handleMetadata();
    }

    // Handle a Clockwork Web interface request if routing is enabled
    protected function handleWebRequest(ServerRequestInterface $request)
    {
        $path = is_string($this->clockwork->getConfig()['web']['enable']) ? $this->clockwork->getConfig()['web']['enable'] : '/clockwork';

        if (! $this->handleRouting) return;
        if ($request->getUri()->getPath() != $path) return;

        return $this->clockwork->usePsrMessage($request, $this->prepareResponse())->returnWeb();
    }

    protected function prepareResponse()
    {
        return new Response();
    }
}
    