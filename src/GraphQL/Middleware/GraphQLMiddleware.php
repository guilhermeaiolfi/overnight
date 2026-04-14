<?php

declare(strict_types=1);

namespace ON\GraphQL\Middleware;

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Laminas\Diactoros\Response\JsonResponse;
use ON\GraphQL\Event\QueryComplete;
use ON\GraphQL\GraphQLContext;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class GraphQLMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected Schema $schema,
		protected bool $debug = false,
		protected bool $allowIntrospection = true,
		protected int $maxDepth = 10,
		protected int $maxComplexity = 100,
		protected ?EventDispatcherInterface $eventDispatcher = null
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$method = $request->getMethod();
		$contentType = $request->getHeaderLine('Content-Type');

		$isGraphQL = $method === 'GET'
			|| ($method === 'POST' && str_contains($contentType, 'application/json'))
			|| ($method === 'POST' && str_contains($contentType, 'multipart/form-data'));

		if (!$isGraphQL) {
			return $handler->handle($request);
		}

		// Check for batch queries (JSON array)
		if ($method === 'POST' && str_contains($contentType, 'application/json')) {
			$rawBody = (string) $request->getBody();
			$decoded = json_decode($rawBody, true);

			if (is_array($decoded) && isset($decoded[0])) {
				$results = [];
				foreach ($decoded as $operation) {
					$results[] = $this->executeSingleQuery(
						$request,
						$operation['query'] ?? null,
						$operation['variables'] ?? null,
						$operation['operationName'] ?? null
					);
				}

				$this->dispatchComplete($results);

				return new JsonResponse($results);
			}
		}

		// Single query
		$result = $this->executeGraphQL($request);

		$this->dispatchComplete($result);

		return new JsonResponse($result);
	}

	protected function dispatchComplete(array $result): void
	{
		if ($this->eventDispatcher !== null) {
			$this->eventDispatcher->dispatch(new QueryComplete($result));
		}
	}

	protected function executeGraphQL(ServerRequestInterface $request): array
	{
		if ($request->getMethod() === 'GET') {
			$params = $request->getQueryParams();
			$query = $params['query'] ?? null;
			$variables = isset($params['variables']) ? json_decode($params['variables'], true) : null;
			$operationName = $params['operationName'] ?? null;
		} elseif (str_contains($request->getHeaderLine('Content-Type'), 'multipart/form-data')) {
			$parsedBody = $request->getParsedBody();
			$operations = json_decode($parsedBody['operations'] ?? '{}', true);
			$map = json_decode($parsedBody['map'] ?? '{}', true);
			$uploadedFiles = $request->getUploadedFiles();

			$query = $operations['query'] ?? null;
			$variables = $operations['variables'] ?? null;
			$operationName = $operations['operationName'] ?? null;

			foreach ($map as $fileKey => $paths) {
				$file = $uploadedFiles[$fileKey] ?? null;
				if ($file === null) {
					continue;
				}
				foreach ($paths as $path) {
					$variables = $this->injectFileIntoVariables($variables, $path, $file);
				}
			}
		} else {
			$body = json_decode((string) $request->getBody(), true) ?? [];
			$query = $body['query'] ?? null;
			$variables = $body['variables'] ?? null;
			$operationName = $body['operationName'] ?? null;
		}

		return $this->executeSingleQuery($request, $query, $variables, $operationName);
	}

	protected function executeSingleQuery(
		ServerRequestInterface $request,
		?string $query,
		mixed $variables,
		?string $operationName
	): array {
		if (!$query) {
			return ['errors' => [['message' => 'No query provided']]];
		}

		$variables = is_array($variables) ? $variables : null;

		// Always build custom validation rules for security
		$validationRules = DocumentValidator::defaultRules();

		if (!$this->allowIntrospection) {
			$validationRules[] = new DisableIntrospection(DisableIntrospection::ENABLED);
		}

		if ($this->maxDepth > 0) {
			$validationRules[] = new QueryDepth($this->maxDepth);
		}

		if ($this->maxComplexity > 0) {
			$validationRules[] = new QueryComplexity($this->maxComplexity);
		}

		// Create context from request
		$context = new GraphQLContext($request);

		$result = GraphQL::executeQuery(
			$this->schema,
			$query,
			null,
			$context,
			$variables,
			$operationName,
			null,
			$validationRules
		);

		$debugFlag = $this->debug
			? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
			: DebugFlag::NONE;

		return $result->toArray($debugFlag);
	}

	/**
	 * Inject an uploaded file into the variables array at the given dot-notation path.
	 * Path format: "variables.avatar" or "variables.input.photo"
	 */
	protected function injectFileIntoVariables(?array $variables, string $path, mixed $file): ?array
	{
		if ($variables === null) {
			$variables = [];
		}

		// Path starts with "variables." — strip it
		$path = preg_replace('/^variables\./', '', $path);
		$keys = explode('.', $path);

		$current = &$variables;
		foreach ($keys as $i => $key) {
			if ($i === count($keys) - 1) {
				$current[$key] = $file;
			} else {
				if (!isset($current[$key]) || !is_array($current[$key])) {
					$current[$key] = [];
				}
				$current = &$current[$key];
			}
		}

		return $variables;
	}
}
