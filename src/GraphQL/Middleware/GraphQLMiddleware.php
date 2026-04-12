<?php

declare(strict_types=1);

namespace ON\GraphQL\Middleware;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class GraphQLMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected Schema $schema
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$method = $request->getMethod();
		$contentType = $request->getHeaderLine('Content-Type');

		if ($method === 'GET' || ($method === 'POST' && str_contains($contentType, 'application/json'))) {
			$result = $this->executeGraphQL($request);
			$response = $handler->handle($request);
			$response->getBody()->write(json_encode($result));

			return $response->withHeader('Content-Type', 'application/json');
		}

		return $handler->handle($request);
	}

	protected function executeGraphQL(ServerRequestInterface $request): array
	{
		if ($request->getMethod() === 'GET') {
			$query = $request->getQueryParams()['query'] ?? null;
			$variables = $request->getQueryParams()['variables'] ?? null;
			$operationName = $request->getQueryParams()['operationName'] ?? null;
		} else {
			$body = json_decode((string) $request->getBody(), true) ?? [];
			$query = $body['query'] ?? null;
			$variables = $body['variables'] ?? null;
			$operationName = $body['operationName'] ?? null;
		}

		if (! $query) {
			return ['errors' => [['message' => 'No query provided']]];
		}

		$variables = is_array($variables) ? $variables : null;

		try {
			$result = GraphQL::executeQuery(
				$this->schema,
				$query,
				null,
				null,
				$variables,
				$operationName
			);

			return $result->toArray();
		} catch (Throwable $e) {
			return [
				'errors' => [
					['message' => $e->getMessage()],
				],
			];
		}
	}
}
