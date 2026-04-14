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
		protected ?\Closure $onComplete = null
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$method = $request->getMethod();
		$contentType = $request->getHeaderLine('Content-Type');

		$isGraphQL = $method === 'GET'
			|| ($method === 'POST' && str_contains($contentType, 'application/json'));

		if (!$isGraphQL) {
			return $handler->handle($request);
		}

		$result = $this->executeGraphQL($request);

		if ($this->onComplete !== null) {
			($this->onComplete)();
		}

		return new JsonResponse($result);
	}

	protected function executeGraphQL(ServerRequestInterface $request): array
	{
		if ($request->getMethod() === 'GET') {
			$params = $request->getQueryParams();
			$query = $params['query'] ?? null;
			$variables = isset($params['variables']) ? json_decode($params['variables'], true) : null;
			$operationName = $params['operationName'] ?? null;
		} else {
			$body = json_decode((string) $request->getBody(), true) ?? [];
			$query = $body['query'] ?? null;
			$variables = $body['variables'] ?? null;
			$operationName = $body['operationName'] ?? null;
		}

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

		$result = GraphQL::executeQuery(
			$this->schema,
			$query,
			null,
			null,
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
}
