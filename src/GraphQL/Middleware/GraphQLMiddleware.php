<?php

declare(strict_types=1);

namespace ON\GraphQL\Middleware;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\SyntaxError;
use GraphQL\GraphQL;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
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
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class GraphQLMiddleware implements MiddlewareInterface
{
	private const INVALID_OPERATION_NAME = 'Invalid "operationName". Expected a GraphQL name.';

	public function __construct(
		protected Schema $schema,
		protected bool $debug = false,
		protected bool $allowIntrospection = false,
		protected int $maxDepth = 10,
		protected int $maxComplexity = 100,
		protected ?EventDispatcherInterface $eventDispatcher = null,
		protected int $maxBatchSize = 10,
		protected int $maxMultipartPathDepth = 10,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$method = strtoupper($request->getMethod());
		$contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));

		$isJsonPost = $method === 'POST' && $contentType === 'application/json';
		$isMultipartPost = $method === 'POST' && $contentType === 'multipart/form-data';
		$isGraphQL = $method === 'GET' || $isJsonPost || $isMultipartPost;

		if (! $isGraphQL) {
			return $handler->handle($request);
		}

		if ($isJsonPost) {
			return $this->processJsonPost($request);
		}

		if ($isMultipartPost) {
			return $this->processMultipartPost($request);
		}

		return $this->processGet($request);
	}

	protected function processJsonPost(ServerRequestInterface $request): ResponseInterface
	{
		$rawBody = (string) $request->getBody();

		if (trim($rawBody) === '') {
			return $this->errorResponse('Invalid JSON request body.', 400);
		}

		$decoded = json_decode($rawBody, true);
		if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
			return $this->errorResponse('Invalid JSON request body.', 400);
		}

		// Batch query (JSON array)
		if (array_is_list($decoded)) {
			if ($decoded === []) {
				return $this->errorResponse('Batch request must contain at least one operation.', 400);
			}

			if ($this->maxBatchSize > 0 && count($decoded) > $this->maxBatchSize) {
				return $this->errorResponse(
					sprintf('Batch size exceeds maximum allowed (%d).', $this->maxBatchSize),
					400
				);
			}

			$results = [];

			try {
				foreach ($decoded as $operation) {
					if (! is_array($operation)) {
						$results[] = ['errors' => [['message' => 'Invalid batch operation.']]];

						continue;
					}

					$operationName = $operation['operationName'] ?? null;
					if (! $this->isValidOperationName($operationName)) {
						$results[] = ['errors' => [['message' => self::INVALID_OPERATION_NAME]]];

						continue;
					}

					$results[] = $this->executeSingleQuery(
						$request,
						isset($operation['query']) && is_string($operation['query']) ? $operation['query'] : null,
						$operation['variables'] ?? null,
						$operationName
					);
				}
			} finally {
				$this->dispatchComplete($results);
			}

			return new JsonResponse($results);
		}

		// Single JSON operation (using already-decoded body; never reads stream twice)
		$query = isset($decoded['query']) && is_string($decoded['query']) ? $decoded['query'] : null;
		$variables = $decoded['variables'] ?? null;
		$operationName = $decoded['operationName'] ?? null;
		if (! $this->isValidOperationName($operationName)) {
			return $this->errorResponse(self::INVALID_OPERATION_NAME, 400);
		}

		$result = [];

		try {
			$result = $this->executeSingleQuery($request, $query, $variables, $operationName);
		} finally {
			$this->dispatchComplete($result);
		}

		return new JsonResponse($result);
	}

	protected function processGet(ServerRequestInterface $request): ResponseInterface
	{
		$params = $request->getQueryParams();
		$query = isset($params['query']) && is_string($params['query']) ? $params['query'] : null;
		$operationName = $params['operationName'] ?? null;
		if (! $this->isValidOperationName($operationName)) {
			return $this->errorResponse(self::INVALID_OPERATION_NAME, 400);
		}
		$variables = null;

		if (isset($params['variables'])) {
			if (is_string($params['variables'])) {
				$decodedVars = json_decode($params['variables'], true);
				if (json_last_error() !== JSON_ERROR_NONE || ($decodedVars !== null && ! is_array($decodedVars))) {
					return $this->errorResponse('Invalid JSON in "variables" query parameter.', 400);
				}
				$variables = $decodedVars;
			} elseif (is_array($params['variables'])) {
				$variables = $params['variables'];
			} else {
				return $this->errorResponse('Invalid "variables" query parameter.', 400);
			}
		}

		$result = [];

		try {
			if ($query !== null && trim($query) !== '') {
				try {
					$operation = AST::getOperationAST(Parser::parse($query), $operationName);
				} catch (SyntaxError) {
					// Let GraphQL format syntax errors as part of its normal validation.
					$operation = null;
				}

				if ($operation !== null && $operation->operation !== 'query') {
					$result = ['errors' => [['message' => sprintf('Operation "%s" is not allowed over HTTP GET.', $operation->operation)]]];

					return new JsonResponse($result, 405, ['Allow' => 'POST']);
				}
			}

			$result = $this->executeSingleQuery($request, $query, $variables, $operationName);
		} finally {
			$this->dispatchComplete($result);
		}

		return new JsonResponse($result);
	}

	protected function processMultipartPost(ServerRequestInterface $request): ResponseInterface
	{
		$parsedBody = $request->getParsedBody();
		if (! is_array($parsedBody)) {
			return $this->errorResponse('Invalid multipart request body.', 400);
		}

		if (! isset($parsedBody['operations']) || ! is_string($parsedBody['operations'])) {
			return $this->errorResponse('Missing or invalid multipart "operations" field.', 400);
		}

		$operations = json_decode($parsedBody['operations'], true);
		if (json_last_error() !== JSON_ERROR_NONE || ! is_array($operations) || array_is_list($operations)) {
			return $this->errorResponse('Invalid JSON in multipart "operations" field.', 400);
		}

		$map = [];
		if (array_key_exists('map', $parsedBody)) {
			if (! is_string($parsedBody['map'])) {
				return $this->errorResponse('Invalid multipart "map" field.', 400);
			}
			$decodedMap = json_decode($parsedBody['map'], true);
			if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decodedMap)) {
				return $this->errorResponse('Invalid JSON in multipart "map" field.', 400);
			}
			$map = $decodedMap;
		}

		$uploadedFiles = $request->getUploadedFiles();

		$query = isset($operations['query']) && is_string($operations['query']) ? $operations['query'] : null;
		$variables = $operations['variables'] ?? null;
		if ($variables !== null && ! is_array($variables)) {
			return $this->errorResponse('Invalid "variables" in multipart "operations" field.', 400);
		}
		$operationName = $operations['operationName'] ?? null;
		if (! $this->isValidOperationName($operationName)) {
			return $this->errorResponse(self::INVALID_OPERATION_NAME, 400);
		}

		foreach ($map as $fileKey => $paths) {
			if (! is_array($paths)) {
				return $this->errorResponse('Invalid multipart "map" entry: paths must be an array.', 400);
			}

			$file = $uploadedFiles[$fileKey] ?? null;
			if (! $file instanceof UploadedFileInterface) {
				return $this->errorResponse('Missing or invalid multipart file.', 400);
			}

			foreach ($paths as $path) {
				if (! is_string($path) || ! $this->isValidMultipartPath($path)) {
					return $this->errorResponse('Invalid multipart file path.', 400);
				}

				$variables = $this->injectFileIntoVariables($variables, $path, $file);
				if ($variables === null) {
					return $this->errorResponse('Multipart files must replace null variable values.', 400);
				}
			}
		}

		$result = [];

		try {
			$result = $this->executeSingleQuery($request, $query, $variables, $operationName);
		} finally {
			$this->dispatchComplete($result);
		}

		return new JsonResponse($result);
	}

	private function isValidOperationName(mixed $name): bool
	{
		return $name === null || (is_string($name) && preg_match('/^[_A-Za-z][_0-9A-Za-z]*$/D', $name) === 1);
	}

	protected function dispatchComplete(array $result): void
	{
		if ($this->eventDispatcher !== null) {
			$this->eventDispatcher->dispatch(new QueryComplete($result));
		}
	}

	protected function executeSingleQuery(
		ServerRequestInterface $request,
		?string $query,
		mixed $variables,
		?string $operationName
	): array {
		if ($query === null || trim($query) === '') {
			return ['errors' => [['message' => 'No query provided']]];
		}

		$variables = is_array($variables) ? $variables : null;

		// Always build custom validation rules for security
		$validationRules = DocumentValidator::defaultRules();

		if (! $this->allowIntrospection) {
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

	protected function isValidMultipartPath(string $path): bool
	{
		if (! str_starts_with($path, 'variables.')) {
			return false;
		}

		$relative = substr($path, strlen('variables.'));
		if ($relative === '') {
			return false;
		}

		$segments = explode('.', $relative);
		if ($this->maxMultipartPathDepth > 0 && count($segments) > $this->maxMultipartPathDepth) {
			return false;
		}

		foreach ($segments as $segment) {
			if ($segment === '') {
				return false;
			}
		}

		return true;
	}

	/**
	 * Inject an uploaded file into the variables array at the given dot-notation path.
	 * Path format: "variables.avatar" or "variables.input.photo"
	 */
	protected function injectFileIntoVariables(?array $variables, string $path, mixed $file): ?array
	{
		if ($variables === null) {
			return null;
		}

		$relative = substr($path, strlen('variables.'));
		$keys = explode('.', $relative);

		$current = &$variables;
		$lastIndex = count($keys) - 1;
		foreach ($keys as $i => $key) {
			if (! array_key_exists($key, $current)) {
				return null;
			}

			if ($i === $lastIndex) {
				if ($current[$key] !== null) {
					return null;
				}
				$current[$key] = $file;
			} else {
				if (! is_array($current[$key])) {
					return null;
				}
				$current = &$current[$key];
			}
		}

		return $variables;
	}

	protected function errorResponse(string $message, int $status): JsonResponse
	{
		return new JsonResponse(['errors' => [['message' => $message]]], $status);
	}
}
