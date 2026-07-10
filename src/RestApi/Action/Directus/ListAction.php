<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use function ON\Mapper\map;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\Parser\QueryParserInterface;
use ON\RestApi\Query\QueryContext;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\RegistrySupportTrait;

final class ListAction implements RestActionInterface
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
		$queryParams = is_array($payload['query'] ?? null) ? $payload['query'] : [];
		$context = $this->createContext();
		$query = $this->parser->parse($collection, $queryParams, $context);

		if ($context->isAggregate()) {
			if (! $options['dispatchEvents']) {
				return ['data' => $this->aggregate($query, $context)];
			}

			$event = new ItemList($collection, $query, $context, $options);
			$this->hooks->dispatch($collection, 'list', $event);
			$query = $event->getQuery();
			$context = $event->getContext();

			if ($event->isDefaultPrevented()) {
				return ['data' => $event->getResult() ?? []];
			}

			$result = $this->aggregate($query, $context);
			$responseOptions = $event->getOptions() + ['output' => $options['output']];
			$event->setResult($result);

			return [
				'data' => map($result)
					->using(CollectionRowMapper::class, $collection)
					->from(PhpRepresentation::class)
					->as($responseOptions['output'])
					->collection()
					->toArray(),
			];
		}

		$event = null;
		$responseOptions = null;
		if ($options['dispatchEvents']) {
			$event = new ItemList($collection, $query, $context, $options);
			$this->hooks->dispatch($collection, 'list', $event);
			$query = $event->getQuery();
			$context = $event->getContext();
			$responseOptions = $event->getOptions() + ['output' => $options['output']];

			if ($event->isDefaultPrevented()) {
				$response = ['data' => $event->getResult() ?? []];
				if ($event->getTotalCount() !== null) {
					$response['meta'] = ['filter_count' => $event->getTotalCount()];
				}

				return $response;
			}
		}

		$result = $this->list($collection, $query, $context);
		if (isset($event)) {
			$event->setResult($result['data'] ?? [], $result['meta']['filter_count'] ?? null);
		}

		$result['data'] = map($result['data'] ?? [])
			->using(CollectionRowMapper::class, $collection)
			->from(PhpRepresentation::class)
			->as(($responseOptions ?? $options)['output'])
			->collection()
			->toArray();

		if (($result['meta'] ?? []) === []) {
			unset($result['meta']);
		}

		return $result;
	}

	/**
	 * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
	 */
	private function list(CollectionInterface $collection, SelectQuery $query, QueryContext $context): array
	{
		$meta = [];
		if (in_array('total_count', $context->getMeta(), true)) {
			$meta['total_count'] = $this->countAll($collection);
		}

		if (in_array('filter_count', $context->getMeta(), true)) {
			$meta['filter_count'] = $this->countFiltered($query);
		}

		$rows = $query->fetchAll();
		$rows = $this->renameRelationAliases($rows, $context);

		return [
			'data' => $rows,
			'meta' => $meta,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function aggregate(SelectQuery $query, QueryContext $context): array
	{
		$rows = $query->fetchAll();
		$result = [];
		foreach ($rows as $row) {
			$entry = [];
			foreach ($context->getGroupBy() as $group) {
				$alias = $group['alias'];
				if (array_key_exists($alias, $row)) {
					$entry[$group['responseName']] = $row[$alias];
				}
			}

			foreach ($context->getAggregates() as $aggregate) {
				$alias = $aggregate['alias'];
				if (array_key_exists($alias, $row)) {
					$entry[$aggregate['function']][$aggregate['field']] = $row[$alias];
				}
			}

			$result[] = $entry;
		}

		return $result;
	}

	private function countAll(CollectionInterface $collection): int
	{
		$countQuery = $this->runtime->query($collection);
		$row = $countQuery
			->select(x()->count($countQuery->all())->as('aggregate_count'))
			->fetchOne();

		return (int) ($row['aggregate_count'] ?? 0);
	}

	private function countFiltered(SelectQuery $query): int
	{
		$filtered = $this->runtime->query($query->getCollection());
		foreach ($query->getConditions() as $condition) {
			$filtered->where($condition->bindTo($filtered, from: $query));
		}
		$row = $filtered
			->select(x()->count($filtered->all())->as('aggregate_count'))
			->fetchOne();

		return (int) ($row['aggregate_count'] ?? 0);
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
