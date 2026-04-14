<?php

declare(strict_types=1);

namespace ON\GraphQL\Middleware;

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class GraphQLMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected Schema $schema,
		protected bool $debug = false
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

		$result = GraphQL::executeQuery(
			$this->schema,
			$query,
			null,
			null,
			$variables,
			$operationName
		);

		$debugFlag = $this->debug
			? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
			: DebugFlag::NONE;

		return $result->toArray($debugFlag);
	}
}
