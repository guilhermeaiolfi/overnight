<?php

declare(strict_types=1);

namespace ON\Handler;

use Fig\Http\Message\StatusCodeInterface;
use ON\Application;
use ON\Middleware\OutputTypeMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
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

	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		if ($request->getAttribute(OutputTypeMiddleware::class) != "html") {
			return $this->generatePlainTextResponse($request);
		}

		return $this->app->processForward($this->middleware, $request);
	}

	/**
	 * Generates a plain text response indicating the request method and URI.
	 */
	private function generatePlainTextResponse(ServerRequestInterface $request): ResponseInterface
	{
		$response = $this->responseFactory->createResponse(StatusCodeInterface::STATUS_NOT_FOUND);
		$response->getBody()
			->write(sprintf(
				'Cannot %s %s',
				$request->getMethod(),
				(string) $request->getUri()
			));

		return $response;
	}
}
