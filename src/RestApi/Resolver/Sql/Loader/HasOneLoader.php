<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\ComparisonOperator;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\LiteralValue;
use ON\RestApi\Resolver\DataSourceInterface;

class HasOneLoader extends AbstractRelationLoader
{
	public function configureNode(AbstractNode $parent): AbstractNode
	{
		$node = new SingularNode(
			$this->getSelectColumns(),
			[$this->getPrimaryKeyColumn($this->getTargetCollection())],
			[$this->relation->getOuterField()->getColumn()],
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

		$columns = $this->getSelectColumns();
		$query = $this->baseQuery($columns)
			->where($this->relation->getOuterField()->getColumn(), 'IN', $parentIds);
		$this->applyRelationQueryOptions($query);

		if ($this->limit() !== null || $this->offset() !== null) {
			$query = $this->limitedSubquery(
				$query,
				$columns,
				$this->getTargetCollection()->getTable() . '.' . $this->relation->getOuterField()->getColumn()
			);
		}

		$this->parseLoadedRows($node, $query);
	}

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source,
		DataSourceInterface $dataSource
	): array {
		$payload = parent::normalizePayload($operation, $input, $source, $dataSource);

		if ($input === null) {
			if ($operation !== 'create') {
				$this->normalizeOmittedChildren($payload, $this->currentRelationRows($dataSource, $source));
			}

			return $payload;
		}

		$targetCollection = $this->relation->getCollection();
		$relationKey = $this->relation->getOuterField()->getName();
		$parentId = $source->getValue($this->relation->getInnerField()->getName());

		if ($this->isDetailedPayload($input)) {
			return $this->normalizeDetailedHasRelationPayload($input, $parentId, $relationKey);
		}

		if (!is_array($input)) {
			$current = $operation === 'create' ? null : ($this->currentRelationRows($dataSource, $source)[0] ?? null);
			$currentId = is_array($current) ? $this->inputPrimaryKeyValue($targetCollection, $current) : null;
			if ($currentId !== null && $currentId !== $input) {
				$this->normalizeOmittedChildren($payload, [$current]);
			}
			if ($currentId === null || $currentId !== $input) {
				$payload['connect'][] = $input;
			}

			return $payload;
		}

		// Basic payloads describe the desired final relation state, so we diff
		// them against the current row before queueing any writes.
		$current = $operation === 'create' ? null : ($this->currentRelationRows($dataSource, $source)[0] ?? null);
		$currentId = is_array($current) ? $this->inputPrimaryKeyValue($targetCollection, $current) : null;
		$desired = $input;
		$desiredId = $this->inputPrimaryKeyValue($targetCollection, $desired);

		if ($desiredId === null && $currentId !== null) {
			$desired[$this->getPrimaryKeyName($targetCollection)] = $currentId;
			$desiredId = $currentId;
		}
		if ($currentId !== null && $desiredId !== null && $currentId !== $desiredId) {
			$this->normalizeOmittedChildren($payload, [$current]);
			$payload['connect'][] = $desiredId;
		}

		$desired[$relationKey] = $parentId;
		foreach ($this->normalizeRelationItems($input) as $item) {
			$this->inputPrimaryKeyValue($targetCollection, $desired) === null
				? $payload['create'][] = $desired
				: $payload['update'][] = $desired;
		}

		return $payload;
	}

	protected function mutate(
		array $payload,
		MutationStateInterface $source,
		array $children,
		MutationQueue $queue
	): void {
		$parentId = $source->getValue($this->relation->getInnerField()->getName());
		$targetCollection = $this->getTargetCollection();
		$relationKey = $this->relation->getOuterField()->getName();

		foreach ($payload['connect'] ?? [] as $targetId) {
			$queue->queueUpdate(
				$targetCollection,
				new ComparisonFilter(
					new FieldExpression($this->getPrimaryKeyName($targetCollection)),
					ComparisonOperator::Eq,
					new LiteralValue($targetId)
				),
				[$relationKey => $parentId]
			);
		}

		foreach ($payload['disconnect'] ?? [] as $targetId) {
			$queue->queueUpdate(
				$targetCollection,
				new ComparisonFilter(
					new FieldExpression($this->getPrimaryKeyName($targetCollection)),
					ComparisonOperator::Eq,
					new LiteralValue($targetId)
				),
				[$relationKey => null]
			);
		}

		$this->queueChildMutations($children, $queue);
	}

	protected function normalizeOmittedChildren(array &$payload, array $currentRows): void
	{
		foreach ($currentRows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$id = $this->inputPrimaryKeyValue($this->getTargetCollection(), $row);
			if ($id === null) {
				continue;
			}

			if ($this->relation->isCascade() || !$this->relation->isNullable()) {
				$payload['delete'][] = $id;
				continue;
			}

			$payload['disconnect'][] = $id;
		}
	}

	protected function normalizeDetailedHasRelationPayload(array $input, mixed $parentId, string $relationKey): array
	{
		$payload = $this->normalizeDetailedPayload($input);
		foreach (['create', 'update'] as $mutation) {
			foreach ($payload[$mutation] as $index => $item) {
				if (!is_array($item)) {
					unset($payload[$mutation][$index]);
					continue;
				}

				if ($mutation === 'create') {
					$item[$relationKey] = $parentId;
				}

				$payload[$mutation][$index] = $item;
			}

			$payload[$mutation] = array_values($payload[$mutation]);
		}

		return $payload;
	}
}
