<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\Parser\QueryParserInterface;
use ON\RestApi\Query\QueryContext;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\PrimaryKey;
use ON\Data\Key;
use ON\RestApi\Support\RegistrySupportTrait;

final class GetAction implements RestActionInterface
{
	use RegistrySupportTrait;

	private QueryParserInterface $parser;

	public function __construct(
		private Registry $registry,
		private DataRuntime $runtime,
		private RestApiConfig $config,
		private RestHookDispatcher $hooks,
		?QueryParserInterface $parser = null,
	) {
		$this->parser = $parser ?? new DirectusQueryParser($runtime);
	}

	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		$payload = is_array($payload) ? $payload : [];
		$options = ($options ?? []) + [
			'dispatchEvents' => true,
			'output' => PhpRepresentation::class,
		];
		$collection = $this->getCollectionOrThrow($this->registry, (string) ($params['collection'] ?? ''));
		$identity = PrimaryKey::of($collection)->getValue((string) ($params['id'] ?? ''));
		$queryParams = is_array($payload['query'] ?? null) ? $payload['query'] : [];
		$context = $this->createContext();
		$query = $this->parser->parse($collection, $queryParams, $context);
		$this->applyIdentity($query, $collection, $identity);
		$query->limit(1);

		if (! $options['dispatchEvents']) {
			$item = $this->mapRowOutput(
				$collection,
				$this->fetchOne($query, $context),
				PhpRepresentation::class,
				$options['output'],
			);
		} else {
			$event = new ItemGet($collection, $identity, $query, $context, $options);
			$this->hooks->dispatch($collection, 'get', $event);
			$query = $event->getQuery();
			$context = $event->getContext();
			$responseOptions = $event->getOptions() + ['output' => PhpRepresentation::class];

			if ($event->isDefaultPrevented()) {
				$item = $this->mapRowOutput(
					$collection,
					$event->getResult(),
					PhpRepresentation::class,
					$responseOptions['output'],
				);
			} else {
				$event->setResult($this->fetchOne($query, $context));
				$item = $this->mapRowOutput(
					$collection,
					$event->getResult(),
					PhpRepresentation::class,
					$responseOptions['output'],
				);
			}
		}

		if ($item === null) {
			throw RestApiError::notFound();
		}

		return ['data' => $item];
	}

	private function fetchOne(SelectQuery $query, QueryContext $context): ?array
	{
		$row = $query->fetchOne();
		if ($row === null) {
			return null;
		}

		$rows = $this->renameRelationAliases([$row], $context);

		return $rows[0] ?? null;
	}

	private function applyIdentity(
		SelectQuery $query,
		CollectionInterface $collection,
		Key $identity,
	): void {
		foreach (PrimaryKey::of($collection)->getFields() as $field) {
			$query->where(x()->eq($query->field($field->getName()), $identity->getFieldValue($field->getName())));
		}
	}

	/**
	 * @param class-string<RepresentationInterface> $from
	 * @param class-string<RepresentationInterface> $to
	 */
	private function mapRowOutput(
		CollectionInterface $collection,
		?array $row,
		string $from,
		string $to,
	): ?array {
		if ($row === null) {
			return null;
		}

		return map($row)
			->args($collection)
			->from($from)
			->as($to)
			->to([]);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function renameRelationAliases(array $rows, QueryContext $context): array
	{
		if ($context->getRelationResponseNames() === []) {
			return $rows;
		}

		foreach ($rows as &$row) {
			foreach ($context->getRelationResponseNames() as $relationPath => $responseName) {
				$segments = explode('.', $relationPath);
				$relationName = $segments[0] ?? null;
				if ($relationName === null || $relationName === $responseName) {
					continue;
				}
				if (! array_key_exists($relationName, $row)) {
					continue;
				}
				$row[$responseName] = $row[$relationName];
				unset($row[$relationName]);
			}
		}
		unset($row);

		return $rows;
	}

	private function createContext(): QueryContext
	{
		return new QueryContext(
			defaultLimit: (int) $this->config->get('defaultLimit', 100),
			maxLimit: (int) $this->config->get('maxLimit', 1000),
			dynamicVariables: $this->config->get('dynamicVariables', []),
		);
	}
}
