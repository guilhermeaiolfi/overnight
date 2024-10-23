<?php
declare(strict_types=1);

namespace ON\Handler;

use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use ON\Router\RouteResult;
use ON\Router\Route;

use ON\Application;
use ON\Extension\PipelineExtension;
use ON\Middleware\OutputTypeMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;

use function sprintf;

class NotFoundHandler implements RequestHandlerInterface
{
    /**
    * @param callable|ResponseFactoryInterface $responseFactory
    **/
    public function __construct(
        private Application $app,
        private string $middleware,
        private $responseFactory
    ) {
    }

    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        if ($request->getAttribute(OutputTypeMiddleware::class) != "html") {
            return $this->generatePlainTextResponse($request);
        }

        $route = new Route("/404", $this->middleware, ["GET"], "NOT_FOUND");

        $route_result = RouteResult::fromRoute($route);

        $request = $this->app->ext('pipeline')->prepareRequestFromRouteResult($route_result, $request);

        return $this->app->runAction($request);
    }

    /**
     * Generates a plain text response indicating the request method and URI.
     */
    private function generatePlainTextResponse(ServerRequestInterface $request) : ResponseInterface
    {
        $response = ($this->responseFactory)()->withStatus(StatusCodeInterface::STATUS_NOT_FOUND);
        $response->getBody()
            ->write(sprintf(
                'Cannot %s %s',
                $request->getMethod(),
                (string) $request->getUri()
            ));

        return $response;
    }

}
