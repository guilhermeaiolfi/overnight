<?php

declare(strict_types=1);

namespace ON\RestApi\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Http\Exception\InvalidMultipartFormDataException;
use ON\Http\MultipartFormDataParser;
use ON\RestApi\Action\RestActionRouter;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\RequestComplete;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

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
		$basePath = rtrim($this->options['endpointUri'] ?? '/items', '/');
		$path = $request->getUri()->getPath();

		$prefixStripped = ($this->options['pathPrefixStripped'] ?? false) === true;
		if (! $prefixStripped && ! $this->matchesBasePath($path, $basePath)) {
			return $handler->handle($request);
		}

		try {
			if (is_callable($this->activate)) {
				($this->activate)();
			}

			$routeResult = $this->router->match(
				$request->getMethod(),
				$prefixStripped ? $path : $this->extractRemainder($path, $basePath)
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
		} catch (Throwable $e) {
			$debug = $this->options['debug'] ?? false;
			$internal = RestApiError::internal($e, (bool) $debug);
			$response = $this->errorResponse($internal);
		}

		$this->eventDispatcher?->dispatch(new RequestComplete($response));

		return $response;
	}

	protected function payload(ServerRequestInterface $request): array
	{
		$method = strtoupper($request->getMethod());
		$readsBody = ! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
		if ($readsBody) {
			$request = $this->prepareMultipartRequest($request);
		}

		return [
			'query' => $this->getQueryParams($request),
			'body' => $readsBody ? $this->parseRequestBody($request) : [],
			'files' => $readsBody ? $this->resolveUploadedFiles($request) : [],
			'headers' => $this->headers($request),
			'method' => $method,
			'request' => $request,
		];
	}

	protected function parseRequestBody(ServerRequestInterface $request): array
	{
		$contentType = $request->getHeaderLine('Content-Type');

		if ($this->isMultipartRequest($contentType)) {
			$parsedBody = (array) $request->getParsedBody();
			if (! array_key_exists('data', $parsedBody)) {
				return [];
			}

			if (! is_string($parsedBody['data'])) {
				throw RestApiError::invalidJson();
			}

			$data = json_decode($parsedBody['data'], true);
			if (! is_array($data)) {
				throw RestApiError::invalidJson();
			}

			return $data;
		}

		$raw = (string) $request->getBody();
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (! is_array($decoded)) {
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
		return $path === $basePath || str_starts_with($path, $basePath . '/');
	}

	protected function extractRemainder(string $path, string $basePath): string
	{
		if ($this->matchesBasePath($path, $basePath)) {
			return substr($path, strlen($basePath));
		}

		return $path;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function resolveUploadedFiles(ServerRequestInterface $request): array
	{
		return $request->getUploadedFiles();
	}

	private function prepareMultipartRequest(ServerRequestInterface $request): ServerRequestInterface
	{
		$contentType = $request->getHeaderLine('Content-Type');
		if (! $this->isMultipartRequest($contentType)) {
			return $request;
		}

		$body = $request->getParsedBody();
		$files = $request->getUploadedFiles();
		if ($body !== null && $files !== []) {
			return $request;
		}

		$raw = (string) $request->getBody();
		if ($raw === '') {
			return $request;
		}

		try {
			$parsed = (new MultipartFormDataParser())->parse($contentType, $raw);
		} catch (InvalidMultipartFormDataException $exception) {
			throw new RestApiError('Invalid multipart form data.', 'INVALID_PAYLOAD', null, 400, $exception);
		}

		return $request->withParsedBody(array_replace($parsed['parsedBody'], (array) $body))
			->withUploadedFiles($files === [] ? $parsed['uploadedFiles'] : $files);
	}

	protected function isMultipartRequest(string $contentType): bool
	{
		return 1 === preg_match('#^multipart/form-data($|[ ;])#i', $contentType);
	}
}
