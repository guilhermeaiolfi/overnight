<?php

declare(strict_types=1);

namespace ON\RestApi\Directus\Action;

use ON\RestApi\Action\Concern\FormatOutputTrait;
use ON\RestApi\Action\Concern\RegistrySupportTrait;
use ON\RestApi\Action\Directus\Support\DirectusSupportTrait;
use ON\RestApi\Action\RestActionInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Handler\AliasRegistry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\QueryNormalizer;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\Support\AuthorizationGuard;
use Psr\EventDispatcher\EventDispatcherInterface;

final class ListAction implements RestActionInterface
{
	use FormatOutputTrait;
	use RegistrySupportTrait;
	use DirectusSupportTrait;

	public function __construct(
		private Registry $registry,
		private ItemRepositoryInterface $items,
		private HandlerFactory $relationHandlers,
		private DirectusQueryParser $queryParser,
		private QueryNormalizer $queryNormalizer,
		private SqlQuerySpecCompiler $querySpecCompiler,
		private RestApiConfig $config,
		private ?EventDispatcherInterface $eventDispatcher = null,
	) {}

	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		$payload = is_array($payload) ? $payload : [];
		$options = ($options ?? []) + ['serialize' => true, 'dispatchEvents' => true];
		$collection = $this->getCollection((string) ($params['collection'] ?? ''));
		$query = is_array($payload['query'] ?? null) ? $payload['query'] : [];
		$querySpec = $this->queryNormalizer->normalize($this->queryParser->parse($collection, $query));

		if ($querySpec->aggregate !== []) {
			if (!$options['dispatchEvents']) {
				return ['data' => $this->aggregate($collection, $querySpec)];
			}

			$event = new ItemList($collection, $querySpec, $options);
			$this->eventDispatcher?->dispatch($event);
			if ($this->eventDispatcher !== null) {
				AuthorizationGuard::assert($event);
			}
			$querySpec = $event->getQuerySpec();

			if ($event->isDefaultPrevented()) {
				return ['data' => $event->getResult() ?? []];
			}

			$result = $this->aggregate($collection, $querySpec);
			$event->setResult($result);

			return ['data' => $event->getResult() ?? []];
		}

		if ($options['dispatchEvents']) {
			$event = new ItemList($collection, $querySpec, $options);
			$this->eventDispatcher?->dispatch($event);
			if ($this->eventDispatcher !== null) {
				AuthorizationGuard::assert($event);
			}
			$querySpec = $event->getQuerySpec();
			$responseOptions = $event->getOptions();

			if ($event->isDefaultPrevented()) {
				$result = [
					'items' => $this->formatResponseRows($collection, $event->getResult() ?? [], $responseOptions),
					'meta' => $event->getTotalCount() === null ? [] : ['filter_count' => $event->getTotalCount()],
				];
			}
		}

		if (!isset($result)) {
			$result = $this->list($collection, $querySpec);
			if (isset($event)) {
				$event->setResult($result['items'] ?? [], $result['meta']['filter_count'] ?? null);
				$responseOptions = $event->getOptions();
			}

			$result['items'] = $this->formatResponseRows($collection, $result['items'] ?? [], $responseOptions ?? $options);
		}

		$body = ['data' => $result['items'] ?? []];
		$meta = $result['meta'] ?? [];
		if ($meta !== []) {
			$body['meta'] = $meta;
		}

		return $body;
	}

	private function list(CollectionInterface $collection, QuerySpec $querySpec): array
	{
		$selection = $this->buildSelectionPlan($querySpec->selection);
		$requestedColumnNames = $this->fieldNamesToColumnNames($collection, $selection['requestedFields']);
		$internalRelationKeyColumnNames = $this->getRelationKeyColumnNames($collection, $selection['relations']);
		$fieldsForSelect = array_values(array_unique(array_merge(
			$selection['fields'],
			$this->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$aliases = new AliasRegistry();
		$query = $this->items->select($collection, $fieldsForSelect);
		$this->querySpecCompiler->applyFilters($query, $collection, $querySpec->filter, null, $aliases);
		$this->querySpecCompiler->applySearch($query, $collection, $querySpec->search);

		$meta = [];
		if (in_array('total_count', $querySpec->meta, true)) {
			$meta['total_count'] = $this->items->getDatabase()->select()
				->from($collection->getTable())
				->count();
		}

		if (in_array('filter_count', $querySpec->meta, true)) {
			$meta['filter_count'] = $this->items->count($query);
		}

		$this->querySpecCompiler->applyOrderBy($query, $collection, $querySpec->sort);
		$this->querySpecCompiler->applyPagination($query, $querySpec->pagination);

		$rows = $this->items->fetchAll($query);
		$items = $this->fetchData(
			$collection,
			$rows,
			$requestedColumnNames === [] && !$querySpec->selection->explicit
				? $this->fieldNamesToColumnNames($collection, $collection->getVisibleFields())
				: $requestedColumnNames,
			$internalRelationKeyColumnNames,
			$selection['relations'],
			$aliases
		);

		return [
			'items' => $items,
			'meta' => $meta,
		];
	}

	private function aggregate(CollectionInterface $collection, QuerySpec $querySpec): array
	{
		if ($querySpec->aggregate === []) {
			return [];
		}

		$query = $this->items->getDatabase()->select()->from($collection->getTable());
		$this->querySpecCompiler->applyFilters($query, $collection, $querySpec->filter, null, new AliasRegistry());
		$this->querySpecCompiler->applySearch($query, $collection, $querySpec->search);
		$this->querySpecCompiler->applyGroupBy($query, $collection, $querySpec->groupBy);

		$selectExpressions = array_merge(
			$this->querySpecCompiler->compileGroupBySelects($collection, $querySpec->groupBy),
			$this->querySpecCompiler->compileAggregateSelects(
				$collection,
				$querySpec->aggregate,
				null,
				fn(string $function, string $field): string => $this->aggregateAlias($function, $field)
			)
		);

		if ($selectExpressions === []) {
			return [];
		}

		$query->columns($selectExpressions);

		return $this->formatAggregateRows(
			$this->items->fetchAll($query),
			$querySpec->aggregate,
			$querySpec->groupBy
		);
	}

	private function fetchData(
		CollectionInterface $collection,
		array $rows,
		array $requestedColumnNames,
		array $internalRelationKeyColumnNames,
		array $relations,
		?AliasRegistry $aliases = null
	): array {
		if ($rows === []) {
			return [];
		}

		$root = $this->relationHandlers->configuredRoot(
			$collection,
			$rows,
			array_keys($rows[0]),
			$requestedColumnNames,
			$internalRelationKeyColumnNames,
			$relations,
			$aliases ?? new AliasRegistry()
		);

		return $root->fetchData();
	}

}
