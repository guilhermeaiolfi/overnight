<?php

/**
 * @see       https://github.com/mezzio/mezzio for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ON\Handler;

use Fig\Http\Message\StatusCodeInterface;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Mezzio\Router\RouteResult;
use Mezzio\Router\Route;

use ON\Application;

use function sprintf;

class NotFoundHandler implements RequestHandlerInterface
{
    /**
     * @var callable
     */
    private $responseFactory;

    /**
     * @var string
     */
    private $template;

    /**
     * @var string
     */
    private $middleware;

    /**
     * @todo Allow nullable $layout
     */
    public function __construct(
        Application $app,
        $middleware,
        callable $responseFactory
    ) {
        $this->app = $app;
        $this->middleware = $middleware;
        $this->responseFactory = $responseFactory;
    }

    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        if ($request->getAttribute("OUTPUT_TYPE") != "html") {
            return $this->generatePlainTextResponse($request);
        }

        if (is_string($this->middleware)) { //middleware
            $middleware = $this->app->factory->prepare($this->middleware);
        }

        $route = new Route("/404", $middleware, ["GET"], "NOT_FOUND");

        $route_result = RouteResult::fromRoute($route);

        $request = $request->withAttribute(RouteResult::class, $route_result);

        $request = $request->withAttribute("PARENT-REQUEST", $request);

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
