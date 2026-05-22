<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\Database\Injection\Expression;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
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

final class ManyToManyLoader extends AbstractRelationLoader
{
	private ?string $junctionAlias = null;
	private ?string $targetAlias = null;

	public function __construct(
		private M2MRelation $manyToMany,
		?RelationSelection $selection = null,
		?QueryContext $context = null
	) {
		parent::__construct($manyToMany, $selection, $context);
	}

	public function configureNode(AbstractNode $parent): AbstractNode
	{
		$node = new ArrayNode(
			$this->resultNodeColumns(),
			$this->pivotPrimaryKeyColumns(),
			[$this->throughInnerKeyColumn()],
			[$this->relation->getInnerField()->getColumn()]
		);
		$parent->linkNode($this->getResponseName(), $node);
		$this->setNode($node);

		return $node;
	}

	public function load(): void
	{
		$node = $this->getNode();
		$parentIds = $this->flattenedReferenceValues($node);
		if ($parentIds === []) {
			return;
		}

		$through = $this->manyToMany->through;
		$junctionAlias = $this->junctionAlias();
		$targetAlias = $this->targetAlias();
		$throughInnerKey = $through->getInnerField()->getColumn();
		$throughOuterKey = $through->getOuterField()->getColumn();
		$targetPkColumn = $this->getPrimaryKeyColumn($this->getTargetCollection());

		$selectColumns = $this->selectColumns($targetAlias, $junctionAlias, $throughInnerKey);
		$query = $this->context->database->select($selectColumns)
			->from($through->getCollection()->getTable() . ' AS ' . $junctionAlias)
			->innerJoin($this->getTargetCollection()->getTable(), $targetAlias)
			->on(
				$junctionAlias . '.' . $throughOuterKey,
				'=',
				$targetAlias . '.' . $targetPkColumn
			)
			->where($junctionAlias . '.' . $throughInnerKey, 'IN', $parentIds);

		if ($this->filters() !== null) {
			$this->context->filterApplier->applyNode(
				$query,
				$this->getTargetCollection(),
				$this->filters(),
				$targetAlias,
				$this->context->aliases
			);
		}

		foreach ($this->orderBy() as $order) {
			if (!is_array($order) || !isset($order['expression'])) {
				continue;
			}

			$query->orderBy($order['expression'], $order['direction'] ?? 'ASC');
		}

		if ($this->limit() !== null || $this->offset() !== null) {
			$query = $this->limitedSubqueryWithColumns(
				$query,
				$selectColumns,
				$this->resultNodeColumns(),
				$junctionAlias . '.' . $throughInnerKey
			);
		}

		$this->parseLoadedRows($node, $query);
	}

	private function resultNodeColumns(): array
	{
		$columns = $this->getSelectColumns();
		foreach ($this->pivotNodeColumns() as $column) {
			$columns[] = $column;
		}

		return array_values(array_unique($columns));
	}

	private function selectColumns(string $targetAlias, string $junctionAlias, string $throughInnerKey): array
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
		return array_values(array_unique([
			$this->throughInnerKeyColumn(),
			...$this->pivotPrimaryKeyColumns(),
		]));
	}

	private function pivotPrimaryKeyColumns(): array
	{
		$columns = [];
		foreach ((array) $this->manyToMany->through->getCollection()->getPrimaryKey() as $field) {
			$columns[] = $field->getColumn();
		}

		return $columns !== []
			? $columns
			: [$this->throughInnerKeyColumn(), $this->manyToMany->through->getOuterField()->getColumn()];
	}

	private function throughInnerKeyColumn(): string
	{
		return $this->manyToMany->through->getInnerField()->getColumn();
	}

	private function junctionAlias(): string
	{
		return $this->junctionAlias ??= $this->context->aliases->alias('__on_' . $this->getResponseName() . '_junction');
	}

	private function targetAlias(): string
	{
		return $this->targetAlias ??= $this->context->aliases->alias('__on_' . $this->getResponseName() . '_target');
	}

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source
	): array {
		$payload = parent::normalizePayload($operation, $input, $source);

		if (!is_array($input)) {
			$payload['connect'][] = $input;

			return $payload;
		}

		if ($this->isAssociativeArray($input)) {
			foreach (['create', 'update', 'delete', 'connect', 'disconnect'] as $key) {
				$payload[$key] = $this->normalizeRelationItems($input[$key] ?? []);
			}

			return $payload;
		}

		$targetCollection = $this->relation->getCollection();
		foreach ($input as $item) {
			if (!is_array($item)) {
				$payload['connect'][] = $item;
				continue;
			}

			$this->inputPrimaryKeyValue($targetCollection, $item) === null
				? $payload['create'][] = $item
				: $payload['update'][] = $item;
		}

		return $payload;
	}

	protected function mutate(
		array $payload,
		MutationStateInterface $source,
		array $children,
		MutationQueue $queue
	): void {
		$this->queueChildMutations($children, $queue);

		$parentId = $source->getValue($this->relation->getInnerField()->getName());

		foreach ($payload['disconnect'] ?? [] as $targetId) {
			$this->disconnect($queue, $parentId, $targetId);
		}

		foreach ($payload['connect'] ?? [] as $targetId) {
			$this->connect($queue, $parentId, $targetId);
		}

		$targetCollection = $this->relation->getCollection();
		foreach ($children['create'] ?? [] as $created) {
			if ($created instanceof MutationStateInterface) {
				$this->connect($queue, $parentId, $created->getValue($this->getPrimaryKeyName($targetCollection)));
			}
		}
	}

	private function connect(MutationQueue $queue, mixed $parentId, mixed $targetId): void
	{
		$through = $this->manyToMany->through;

		$queue->queueInsert(new MutationState($through->getCollection(), [
			$through->getInnerField()->getName() => $parentId,
			$through->getOuterField()->getName() => $targetId,
		]), true);
	}

	private function disconnect(MutationQueue $queue, mixed $parentId, mixed $targetId): void
	{
		$through = $this->manyToMany->through;

		$queue->queueDelete($through->getCollection(), new LogicalFilter(LogicalOperator::And, [
			new ComparisonFilter(
				new FieldExpression($through->getInnerField()->getName()),
				ComparisonOperator::Eq,
				new LiteralValue($parentId)
			),
			new ComparisonFilter(
				new FieldExpression($through->getOuterField()->getName()),
				ComparisonOperator::Eq,
				new LiteralValue($targetId)
			),
		]));
	}
}
