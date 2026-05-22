<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\Database\Injection\Expression;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\ComparisonOperator;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\LiteralValue;
use ON\RestApi\Query\Node\LogicalFilter;
use ON\RestApi\Query\Node\LogicalOperator;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\Support\PrimaryKeyCriteria;

class ManyToManyHandler extends AbstractRelationHandler
{
	private ?string $junctionAlias = null;
	private ?string $targetAlias = null;

	public function __construct(
		CollectionInterface $collection,
		private M2MRelation $manyToMany,
		?RelationSelection $selection = null,
		?SqlDataSource $dataSource = null,
		?SqlQuerySpecCompiler $querySpecCompiler = null,
		?AliasRegistry $aliases = null
	) {
		parent::__construct($collection, $manyToMany, $selection, $dataSource, $querySpecCompiler, $aliases);
	}

	public function configureParserNode(AbstractNode $parent): AbstractNode
	{
		$node = new ArrayNode(
			$this->resultNodeColumns(),
			$this->pivotPrimaryKeyColumns(),
			$this->throughInnerKeyColumns(),
			$this->relationInnerKeyColumns()
		);
		$parent->linkNode($this->getResponseName(), $node);
		$this->setNode($node);

		return $node;
	}

	public function load(): mixed
	{
		$node = $this->getNode();
		$parentKeySets = $this->getReferenceValueSets($node);
		if ($parentKeySets === []) {
			return null;
		}

		$through = $this->manyToMany->through;
		$junctionAlias = $this->junctionAlias();
		$targetAlias = $this->targetAlias();
		$throughInnerKeys = $this->throughInnerKeyColumns();
		$throughOuterKeys = $this->throughOuterKeyColumns();
		$targetKeyColumns = $this->targetOuterKeyColumns();

		$selectColumns = $this->selectColumns($targetAlias, $junctionAlias);
		$query = $this->dataSource->getDatabase()->select($selectColumns)
			->from($through->getCollection()->getTable() . ' AS ' . $junctionAlias)
			->innerJoin($this->getTargetCollection()->getTable(), $targetAlias);
		foreach ($throughOuterKeys as $index => $throughOuterKey) {
			$method = $index === 0 ? 'on' : 'andOn';
			$query->{$method}($junctionAlias . '.' . $throughOuterKey, '=', $targetAlias . '.' . $targetKeyColumns[$index]);
		}
		if (count($throughInnerKeys) === 1) {
			$query->where($junctionAlias . '.' . $throughInnerKeys[0], 'IN', array_map(
				static fn(array $set): mixed => reset($set),
				$parentKeySets
			));
		} else {
			$query->where(function ($nested) use ($parentKeySets, $throughInnerKeys, $junctionAlias) {
				foreach ($parentKeySets as $set) {
					$conditions = [];
					foreach (array_values($throughInnerKeys) as $index => $column) {
						$conditions[$junctionAlias . '.' . $column] = array_values($set)[$index] ?? null;
					}
					$nested->orWhere($conditions);
				}
			});
		}

		if ($this->selection !== null) {
			$this->querySpecCompiler->applyFilters(
				$query,
				$this->getTargetCollection(),
				$this->selection->query->filter,
				$targetAlias,
				$this->aliases
			);
			$this->querySpecCompiler->applySearch(
				$query,
				$this->getTargetCollection(),
				$this->selection->query->search
			);
			$this->querySpecCompiler->applyOrderBy(
				$query,
				$this->getTargetCollection(),
				$this->selection->query->sort,
				$targetAlias
			);
		}

		if ($this->limit() !== null || $this->offset() !== null) {
			$query = $this->limitedSubqueryWithColumns(
				$query,
				$selectColumns,
				$this->resultNodeColumns(),
				$junctionAlias . '.' . $throughInnerKeys[0]
			);
		}

		$this->parseLoadedRows($node, $query);

		return null;
	}

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source,
		SqlDataSource $dataSource
	): array {
		$payload = parent::normalizePayload($operation, $input, $source, $dataSource);
		$throughCollection = $this->manyToMany->through->getCollection();
		$throughOuterKeys = $this->manyToMany->through->throughOuterKeys();
		$throughPrimaryKey = $throughCollection->getPrimaryKey()->getFieldNames()[0] ?? 'id';
		$currentRows = $operation === 'create' ? [] : $this->currentPivotRows($dataSource, $source);
		$currentByPivotId = [];
		$currentByTargetId = [];

		foreach ($currentRows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$pivotId = $this->getInputPrimaryKeyValue($throughCollection, $row);
			$targetId = $this->extractThroughTargetIdentity($row);
			if ($pivotId !== null) {
				$currentByPivotId[$pivotId->toUrlId()] = $row;
			}
			if ($targetId !== null) {
				$currentByTargetId[$targetId->toUrlId()] = $row;
			}
		}

		if (!is_array($input)) {
			$payload['connect'][] = $input;

			return $payload;
		}

		if ($this->isDetailedPayload($input)) {
			return $this->normalizeDetailedManyToManyPayload($input, $source);
		}

		$seenPivot = [];
		$seenTarget = [];
		foreach ($input as $item) {
			if (!is_array($item)) {
				$payload['connect'][] = $item;
				$seenTarget[(string) $item] = true;
				continue;
			}

			if ($this->isThroughPayload($item)) {
				$pivotId = array_key_exists($throughPrimaryKey, $item) ? $item[$throughPrimaryKey] : null;
				$targetId = $this->extractThroughTargetIdentity($item);

				if ($pivotId !== null && isset($currentByPivotId[(string) $pivotId])) {
					$key = $pivotId instanceof \ON\ORM\Definition\Collection\PrimaryKeyValue ? $pivotId->toUrlId() : (string) $pivotId;
					$seenPivot[$key] = true;
					$payload['update'][] = $this->normalizeThroughPayload($source, $item);
					continue;
				}

				if ($targetId !== null && isset($currentByTargetId[$targetId->toUrlId()])) {
					$existing = $currentByTargetId[$targetId->toUrlId()];
					$existingPivotId = $this->getInputPrimaryKeyValue($throughCollection, $existing);
					if ($existingPivotId !== null) {
						$seenPivot[$existingPivotId->toUrlId()] = true;
						foreach ($existingPivotId->values() as $fieldName => $value) {
							$item[$fieldName] = $value;
						}
					}
					$seenTarget[$targetId->toUrlId()] = true;
					$payload['update'][] = $this->normalizeThroughPayload($source, $item);
					continue;
				}

				if ($targetId !== null) {
					$seenTarget[$targetId->toUrlId()] = true;
				}
				$payload['create'][] = $this->normalizeThroughPayload($source, $item);
				continue;
			}

			$targetCollection = $this->relation->getCollection();
			$targetId = $this->getInputPrimaryKeyValue($targetCollection, $item);
			if ($targetId === null) {
				$payload['create'][] = $item;
				continue;
			}

			$seenTarget[$targetId->toUrlId()] = true;
			if (isset($currentByTargetId[$targetId->toUrlId()])) {
				$payload['update'][] = $item;
				continue;
			}

			$payload['connect'][] = $targetId;
			if (count($item) > 1) {
				$payload['update'][] = $item;
			}
		}

		foreach ($currentRows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$pivotId = $this->getInputPrimaryKeyValue($throughCollection, $row);
			$targetId = $this->extractThroughTargetIdentity($row);
			if ($pivotId !== null && isset($seenPivot[$pivotId->toUrlId()])) {
				continue;
			}
			if ($targetId !== null && isset($seenTarget[$targetId->toUrlId()])) {
				continue;
			}

			if ($targetId !== null) {
				$payload['disconnect'][] = $targetId;
			}
		}

		return $payload;
	}

	public function mutationCollection(string $operation, mixed $item): CollectionInterface
	{
		return is_array($item) && $this->isThroughPayload($item)
			? $this->manyToMany->through->getCollection()
			: $this->getTargetCollection();
	}

	public function compileActions(
		MutationQueue $queue,
		MutationStateInterface $source,
		array $actions,
		array $children = []
	): \ON\RestApi\Mutation\MutationTaskInterface|\ON\RestApi\Mutation\MutationDeleteTaskInterface|null {
		$this->queueChildMutations($children, $queue);

		foreach ($actions['disconnect'] ?? [] as $target) {
			$this->disconnect($queue, $this->getParentIdentityFromSource($source), $target);
		}

		foreach ($actions['connect'] ?? [] as $target) {
			$this->connect($queue, $this->getParentIdentityFromSource($source), $target);
		}

		$targetCollection = $this->relation->getCollection();
		foreach ($children['create'] ?? [] as $created) {
			if ($created instanceof MutationStateInterface && $created->getCollection() === $targetCollection) {
				$this->connect(
					$queue,
					$this->getParentIdentityFromSource($source),
					$this->getPrimaryKeyValueFromState($created, false)?->toUrlId() ?? ''
				);
			}
		}

		return null;
	}

	private function resultNodeColumns(): array
	{
		$columns = $this->getSelectColumns();
		foreach ($this->pivotNodeColumns() as $column) {
			$columns[] = $column;
		}

		return array_values(array_unique($columns));
	}

	private function selectColumns(string $targetAlias, string $junctionAlias): array
	{
		$columns = [];
		foreach ($this->getSelectColumns() as $column) {
			$columns[] = new Expression($targetAlias . '.' . $column);
		}
		foreach ($this->pivotNodeColumns() as $column) {
			$columns[] = new Expression($junctionAlias . '.' . $column);
		}

		return $columns;
	}

	private function pivotNodeColumns(): array
	{
		return array_values(array_unique([...$this->throughInnerKeyColumns(), ...$this->pivotPrimaryKeyColumns()]));
	}

	private function pivotPrimaryKeyColumns(): array
	{
		$columns = [];
		foreach ($this->manyToMany->through->getCollection()->getPrimaryKey()->getFields() as $field) {
			$columns[] = $field->getColumn();
		}

		return $columns !== []
			? $columns
			: [...$this->throughInnerKeyColumns(), ...$this->throughOuterKeyColumns()];
	}

	private function throughInnerKeyColumns(): array
	{
		return array_map(
			fn(string $fieldName): string => $this->manyToMany->through->getCollection()->fields->get($fieldName)->getColumn(),
			$this->manyToMany->through->throughInnerKeys()
		);
	}

	private function junctionAlias(): string
	{
		return $this->junctionAlias ??= $this->aliases->alias('__on_' . $this->getResponseName() . '_junction');
	}

	private function targetAlias(): string
	{
		return $this->targetAlias ??= $this->aliases->alias('__on_' . $this->getResponseName() . '_target');
	}

	protected function orderByTableAlias(): ?string
	{
		return $this->targetAlias();
	}

	private function normalizeDetailedManyToManyPayload(array $input, MutationStateInterface $source): array
	{
		$payload = $this->normalizeDetailedPayload($input);
		foreach (['create', 'update', 'delete'] as $operation) {
			foreach ($payload[$operation] as $index => $item) {
				if (!is_array($item) || !$this->isThroughPayload($item)) {
					continue;
				}

				$payload[$operation][$index] = $this->normalizeThroughPayload($source, $item);
			}
		}

		return $payload;
	}

	private function normalizeThroughPayload(MutationStateInterface $source, array $item): array
	{
		$through = $this->manyToMany->through;
		foreach ($this->manyToMany->through->throughInnerKeys() as $index => $throughInnerKey) {
			$item[$throughInnerKey] = $source->getValue($this->relation->innerKeys()[$index]);
		}

		return $item;
	}

	private function isThroughPayload(array $item): bool
	{
		$through = $this->manyToMany->through->getCollection();
		$target = $this->getTargetCollection();

		foreach (array_keys($item) as $key) {
			if (in_array((string) $key, $this->manyToMany->through->throughOuterKeys(), true)) {
				return true;
			}

			if ($through->fields->has((string) $key) && !$target->fields->has((string) $key)) {
				return true;
			}
		}

		return false;
	}

	private function currentPivotRows(SqlDataSource $dataSource, MutationStateInterface $source): array
	{
		$through = $this->manyToMany->through;
		$fieldValueMap = [];
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$fieldValueMap[$through->throughInnerKeys()[$index]] = $source->resolveValue($source->getValue($innerKey));
		}

		return $this->fetchRowsByFields($dataSource, $through->getCollection(), $fieldValueMap);
	}

	private function connect(MutationQueue $queue, PrimaryKeyValue $parentId, mixed $targetId): void
	{
		$through = $this->manyToMany->through;
		$targetIdentity = $targetId instanceof PrimaryKeyValue
			? $targetId
			: PrimaryKeyCriteria::normalize($this->getTargetCollection(), $targetId);
		$payload = [];
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$payload[$through->throughInnerKeys()[$index]] = $parentId->value($innerKey);
		}
		foreach ($this->relation->outerKeys() as $index => $outerKey) {
			$payload[$through->throughOuterKeys()[$index]] = $targetIdentity->value($outerKey);
		}

		$queue->queueInsert(new MutationState($through->getCollection(), $payload), true);
	}

	private function disconnect(MutationQueue $queue, PrimaryKeyValue $parentId, mixed $targetId): void
	{
		$through = $this->manyToMany->through;
		$targetIdentity = $targetId instanceof PrimaryKeyValue
			? $targetId
			: PrimaryKeyCriteria::normalize($this->getTargetCollection(), $targetId);
		$filters = [];
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$filters[] = new ComparisonFilter(
				new FieldExpression($through->throughInnerKeys()[$index]),
				ComparisonOperator::Eq,
				new LiteralValue($parentId->value($innerKey))
			);
		}
		foreach ($this->relation->outerKeys() as $index => $outerKey) {
			$filters[] = new ComparisonFilter(
				new FieldExpression($through->throughOuterKeys()[$index]),
				ComparisonOperator::Eq,
				new LiteralValue($targetIdentity->value($outerKey))
			);
		}

		$queue->queueDelete($through->getCollection(), new LogicalFilter(LogicalOperator::And, $filters));
	}

	private function relationInnerKeyColumns(): array
	{
		return array_map(
			fn(string $fieldName): string => $this->getCollection()->fields->get($fieldName)->getColumn(),
			$this->relation->innerKeys()
		);
	}

	private function throughOuterKeyColumns(): array
	{
		return array_map(
			fn(string $fieldName): string => $this->manyToMany->through->getCollection()->fields->get($fieldName)->getColumn(),
			$this->manyToMany->through->throughOuterKeys()
		);
	}

	private function targetOuterKeyColumns(): array
	{
		return array_map(
			fn(string $fieldName): string => $this->getTargetCollection()->fields->get($fieldName)->getColumn(),
			$this->relation->outerKeys()
		);
	}

	private function getParentIdentityFromSource(MutationStateInterface $source): PrimaryKeyValue
	{
		$values = [];
		foreach ($this->relation->innerKeys() as $key) {
			$values[$key] = $source->getValue($key);
		}

		return new PrimaryKeyValue($this->getCollection(), $values);
	}

	private function extractThroughTargetIdentity(array $row): ?PrimaryKeyValue
	{
		$values = [];
		foreach ($this->manyToMany->through->throughOuterKeys() as $index => $throughOuterKey) {
			if (!array_key_exists($throughOuterKey, $row)) {
				return null;
			}

			$values[$this->relation->outerKeys()[$index]] = $row[$throughOuterKey];
		}

		return new PrimaryKeyValue($this->getTargetCollection(), $values);
	}
}
