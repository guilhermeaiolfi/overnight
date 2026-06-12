<?php

declare(strict_types=1);

namespace ON\RestApi\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use ON\Http\MultipartFormDataParser;
use ON\RestApi\Action\RestActionRouter;
use ON\RestApi\Error\RestApiError;
use ON\Mapper\Representation\WireRepresentation;
use ON\RestApi\Event\RequestComplete;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RestMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected RestActionRouter $router,
		protected mixed $executeAction,
		protected array $options = [],
		protected ?EventDispatcherInterface $eventDispatcher = null,
		protected mixed $activate = null,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$basePath = $this->options['endpointUri'] ?? '/items';
		$path = $request->getUri()->getPath();

		if (!$this->matchesBasePath($path, $basePath)) {
			return $handler->handle($request);
		}

		try {
			if (is_callable($this->activate)) {
				($this->activate)();
			}

			$routeResult = $this->router->match(
				$request->getMethod(),
				$this->extractRemainder($path, $basePath)
			);

			if ($routeResult->isFailure()) {
				throw $routeResult->isMethodFailure()
					? RestApiError::methodNotAllowed()
					: RestApiError::notFound('REST action not found.');
			}

			$route = $routeResult->getMatchedRoute();
			$result = ($this->executeAction)(
				$route->getMiddleware(),
				$routeResult->getMatchedParams(),
				$this->payload($request),
				[
					'input' => WireRepresentation::class,
					'output' => WireRepresentation::class,
					'dispatchEvents' => true,
				],
			);
			$response = $this->toResponse($result);
		} catch (RestApiError $e) {
			$response = $this->errorResponse($e);
		} catch (\Throwable $e) {
			$debug = $this->options['debug'] ?? false;
			$internal = RestApiError::internal($e, (bool) $debug);
			$response = $this->errorResponse($internal);
		}

		$this->eventDispatcher?->dispatch(new RequestComplete($response));

		return $response;
	}

	protected function payload(ServerRequestInterface $request): array
	{
		return [
			'query' => $this->getQueryParams($request),
			'body' => $this->parseRequestBody($request),
			'files' => $this->resolveUploadedFiles($request),
			'headers' => $this->headers($request),
			'method' => strtoupper($request->getMethod()),
			'request' => $request,
		];
	}

	protected function parseRequestBody(ServerRequestInterface $request): array
	{
		$contentType = $request->getHeaderLine('Content-Type');

		if ($this->isMultipartRequest($contentType)) {
			$parsedBody = $request->getParsedBody();
			$data = [];
			if (isset($parsedBody['data'])) {
				$data = json_decode($parsedBody['data'], true);
				if (!is_array($data)) {
					throw RestApiError::invalidJson();
				}
			}

			return $data;
		}

		$raw = (string) $request->getBody();
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			throw RestApiError::invalidJson();
		}

		return $decoded;
	}

	protected function getQueryParams(ServerRequestInterface $request): array
	{
		$query = [];
		parse_str($request->getUri()->getQuery(), $query);

		return array_replace($query, $request->getQueryParams());
	}

	protected function headers(ServerRequestInterface $request): array
	{
		$headers = [];
		foreach ($request->getHeaders() as $name => $values) {
			$headers[$name] = implode(', ', $values);
			$headers[strtolower($name)] = $headers[$name];
		}

		return $headers;
	}

	protected function toResponse(mixed $result): ResponseInterface
	{
		if ($result instanceof ResponseInterface) {
			return $result;
		}

		if ($result === null) {
			return new EmptyResponse(204, ['Cache-Control' => 'no-cache']);
		}

		return new JsonResponse($result, 200, ['Cache-Control' => 'no-cache']);
	}

	protected function errorResponse(RestApiError $error): JsonResponse
	{
		$debug = $this->options['debug'] ?? false;

		return new JsonResponse(
			$error->toArray($debug),
			$error->getHttpStatus(),
			['Cache-Control' => 'no-cache']
		);
	}

	protected function matchesBasePath(string $path, string $basePath): bool
	{
		if (str_starts_with($path, $basePath)) {
			return true;
		}

		return str_starts_with($path, '/');
	}

	protected function extractRemainder(string $path, string $basePath): string
	{
		if (str_starts_with($path, $basePath)) {
			return substr($path, strlen($basePath));
		}

		return $path;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function resolveUploadedFiles(ServerRequestInterface $request): array
	{
		$uploadedFiles = $request->getUploadedFiles();
		if ($uploadedFiles !== []) {
			return $uploadedFiles;
		}

		$contentType = $request->getHeaderLine('Content-Type');
		if (! $this->isMultipartRequest($contentType)) {
			return [];
		}

		$rawBody = (string) $request->getBody();
		if ($rawBody === '') {
			return [];
		}

		return (new MultipartFormDataParser())->parse($contentType, $rawBody)['uploadedFiles'];
	}

	protected function isMultipartRequest(string $contentType): bool
	{
		return 1 === preg_match('#^multipart/form-data($|[ ;])#i', $contentType);
	}
}
