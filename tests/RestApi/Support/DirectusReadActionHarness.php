<?php

declare(strict_types=1);

namespace Tests\ON\RestApi\Support;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Action\Concern\RegistrySupportTrait;
use ON\RestApi\Action\Directus\Support\DirectusSupportTrait;
use ON\RestApi\Handler\AliasRegistry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\Support\PrimaryKeyCriteria;

final class DirectusReadActionHarness
{
	use DirectusSupportTrait;
	use RegistrySupportTrait;

	public function __construct(
		private ItemRepositoryInterface $items,
		private HandlerFactory $handlers,
		private SqlQuerySpecCompiler $querySpecCompiler
	) {
	}

	public function list(CollectionInterface $collection, QuerySpec $querySpec): array
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

	public function get(
		CollectionInterface $collection,
		PrimaryKeyValue|string $identity,
		?QuerySpec $querySpec = null,
	): ?array {
		if ($querySpec === null) {
			return $this->items->findByIdentity($collection, $identity, typed: false);
		}

		$selection = $this->buildSelectionPlan($querySpec->selection);
		$requestedColumnNames = $this->fieldNamesToColumnNames($collection, $selection['requestedFields']);
		$internalRelationKeyColumnNames = $this->getRelationKeyColumnNames($collection, $selection['relations']);
		$fieldsForSelect = array_values(array_unique(array_merge(
			$selection['fields'],
			$this->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$query = $this->items->select($collection, $fieldsForSelect);
		PrimaryKeyCriteria::applyWhere($query, $collection, $identity);
		$query->limit(1);
		$row = $this->items->fetchOne($query);
		if ($row === null) {
			return null;
		}

		$items = $this->fetchData(
			$collection,
			[$row],
			$requestedColumnNames === [] && !$querySpec->selection->explicit
				? $this->fieldNamesToColumnNames($collection, $collection->getVisibleFields())
				: $requestedColumnNames,
			$internalRelationKeyColumnNames,
			$selection['relations']
		);

		return $items[0] ?? null;
	}

	public function aggregate(CollectionInterface $collection, QuerySpec $querySpec): array
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

		$root = $this->handlers->configuredRoot(
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
