<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\ComparisonOperator;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\LiteralValue;
use ON\RestApi\Resolver\DataSourceInterface;

class HasOneHandler extends AbstractRelationHandler
{
	public function configureParserNode(AbstractNode $parent): AbstractNode
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

	public function load(): mixed
	{
		$node = $this->getNode();
		$parentIds = $this->flattenedReferenceValues($node);
		if ($parentIds === []) {
			return null;
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

		return null;
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

	public function compileCreate(
		array $payload,
		MutationStateInterface $source,
		array $children,
		MutationQueue $queue
	): void {
		$this->compileMutationPayload($payload, $source, $children, $queue);
	}

	public function compileUpdate(
		array $payload,
		MutationStateInterface $source,
		array $children,
		MutationQueue $queue
	): void {
		$this->compileMutationPayload($payload, $source, $children, $queue);
	}

	public function compileConnect(mixed $target, MutationStateInterface $source, MutationQueue $queue): void
	{
		$parentId = $source->getValue($this->relation->getInnerField()->getName());
		$targetCollection = $this->getTargetCollection();
		$relationKey = $this->relation->getOuterField()->getName();

		$queue->queueUpdate(
			$targetCollection,
			new ComparisonFilter(
				new FieldExpression($this->getPrimaryKeyName($targetCollection)),
				ComparisonOperator::Eq,
				new LiteralValue($target)
			),
			[$relationKey => $parentId]
		);
	}

	public function compileDisconnect(mixed $target, MutationStateInterface $source, MutationQueue $queue): void
	{
		$targetCollection = $this->getTargetCollection();
		$relationKey = $this->relation->getOuterField()->getName();

		$queue->queueUpdate(
			$targetCollection,
			new ComparisonFilter(
				new FieldExpression($this->getPrimaryKeyName($targetCollection)),
				ComparisonOperator::Eq,
				new LiteralValue($target)
			),
			[$relationKey => null]
		);
	}

	protected function compileMutationPayload(
		array $payload,
		MutationStateInterface $source,
		array $children,
		MutationQueue $queue
	): void {
		foreach ($payload['connect'] ?? [] as $target) {
			$this->compileConnect($target, $source, $queue);
		}

		foreach ($payload['disconnect'] ?? [] as $target) {
			$this->compileDisconnect($target, $source, $queue);
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
