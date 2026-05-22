<?php

declare(strict_types=1);

namespace ON\RestApi\Query;

use Cycle\Database\StatementInterface as CycleStatementInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Handler\AliasRegistry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\HandlerInterface;
use ON\RestApi\Handler\QueryContext;
use ON\RestApi\Handler\RootHandler;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Resolver\Sql\SqlDataSource;

final class QueryPlanner
{
	public function __construct(
		private SqlDataSource $dataSource,
		private HandlerFactory $handlers
	) {
	}

	public function list(CollectionInterface $collection, QuerySpec $querySpec): array
	{
		$selection = $this->dataSource->selectionPlan($querySpec->selection);
		$requestedColumnNames = $this->dataSource->fieldNamesToColumnNames($collection, $selection['requestedFields']);
		$internalRelationKeyColumnNames = $this->dataSource->relationKeyColumnNames($collection, $selection['relations']);
		$fieldsForSelect = array_values(array_unique(array_merge(
			$selection['fields'],
			$this->dataSource->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$aliases = new AliasRegistry();
		$query = $this->dataSource->newSelectQuery($collection, $fieldsForSelect);
		$this->dataSource->filterApplier()->applyNode($query, $collection, $querySpec->filter, null, $aliases);
		$this->dataSource->applySearchField($query, $collection, $querySpec->search);

		$meta = [];
		if (in_array('total_count', $querySpec->meta, true)) {
			$meta['total_count'] = $this->dataSource->database()->select()
				->from($collection->getTable())
				->count();
		}

		if (in_array('filter_count', $querySpec->meta, true)) {
			$meta['filter_count'] = (clone $query)->count();
		}

		$this->dataSource->applySortSpecs($query, $collection, $querySpec->sort);
		$this->dataSource->applyPaginationSpec($query, $querySpec->pagination);

		$rows = $query->fetchAll(CycleStatementInterface::FETCH_ASSOC);

		return [
			'items' => $this->assembleRootRows(
				$collection,
				$rows,
				$requestedColumnNames === [] && !$querySpec->selection->explicit
					? $this->dataSource->getVisibleFields($collection)
					: $requestedColumnNames,
				$internalRelationKeyColumnNames,
				$selection['relations'],
				$aliases
			),
			'meta' => $meta,
		];
	}

	public function get(CollectionInterface $collection, string $id, ?QuerySpec $querySpec = null): ?array
	{
		if ($querySpec === null) {
			return $this->dataSource->getVisibleById($collection, $id);
		}

		$selection = $this->dataSource->selectionPlan($querySpec->selection);
		$requestedColumnNames = $this->dataSource->fieldNamesToColumnNames($collection, $selection['requestedFields']);
		$internalRelationKeyColumnNames = $this->dataSource->relationKeyColumnNames($collection, $selection['relations']);
		$fieldsForSelect = array_values(array_unique(array_merge(
			$selection['fields'],
			$this->dataSource->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$query = $this->dataSource->newSelectQuery($collection, $fieldsForSelect);
		$query->where($this->dataSource->getPrimaryKeyColumn($collection), $id)->limit(1);
		$rows = $query->fetchAll(CycleStatementInterface::FETCH_ASSOC);
		$row = $rows[0] ?? null;
		if ($row === null) {
			return null;
		}

		$items = $this->assembleRootRows(
			$collection,
			[$row],
			$requestedColumnNames === [] && !$querySpec->selection->explicit
				? $this->dataSource->getVisibleFields($collection)
				: $requestedColumnNames,
			$internalRelationKeyColumnNames,
			$selection['relations']
		);

		return $items[0] ?? null;
	}

	private function assembleRootRows(
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

		$root = $this->handlers->root(
			$collection,
			$rows,
			array_keys($rows[0]),
			$requestedColumnNames,
			$internalRelationKeyColumnNames
		);
		$context = $this->dataSource->newQueryContext($aliases ?? new AliasRegistry());

		$this->configureHandlers($root, $collection, $relations, $context);
		$root->parseRows();
		$this->loadHandlers($root->getChildren());

		return $root->result();
	}

	private function configureHandlers(
		HandlerInterface $parent,
		CollectionInterface $collection,
		array $relations,
		QueryContext $context
	): void {
		$parentNode = $parent instanceof RootHandler ? $parent->rootNode() : $parent->getNode();
		foreach ($relations as $selection) {
			if (!$selection instanceof RelationSelection) {
				continue;
			}

			$handler = $this->handlers->relation($collection, $selection, $context);
			if ($handler === null) {
				continue;
			}

			$parent->addChild($handler);
			$handler->configureParserNode($parentNode);
			$handler->prepare();
			$this->configureHandlers($handler, $handler->getTargetCollection(), $handler->getNestedRelations(), $context);
		}
	}

	private function loadHandlers(array $handlers): void
	{
		foreach ($handlers as $handler) {
			$handler->load();
			$this->loadHandlers($handler->getChildren());
		}
	}
}
