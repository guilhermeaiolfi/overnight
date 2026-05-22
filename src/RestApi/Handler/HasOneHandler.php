<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Support\PrimaryKeyCriteria;

class HasOneHandler extends AbstractRelationHandler
{
	public function configureParserNode(AbstractNode $parent): AbstractNode
	{
		$node = new SingularNode(
			$this->getSelectColumns(),
			$this->getPrimaryKeyColumns($this->getTargetCollection()),
			array_map(
				fn(string $fieldName): string => $this->getTargetCollection()->fields->get($fieldName)->getColumn(),
				$this->relation->outerKeys()
			),
			array_map(
				fn(string $fieldName): string => $this->getCollection()->fields->get($fieldName)->getColumn(),
				$this->relation->innerKeys()
			)
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

		$columns = $this->getSelectColumns();
		$query = $this->baseQuery($columns);
		$outerKeyColumns = array_map(
			fn(string $fieldName): string => $this->getTargetCollection()->fields->get($fieldName)->getColumn(),
			$this->relation->outerKeys()
		);
		if (count($outerKeyColumns) === 1) {
			$query->where($outerKeyColumns[0], 'IN', array_map(
				static fn(array $set): mixed => reset($set),
				$parentKeySets
			));
		} else {
			$query->where(function ($nested) use ($parentKeySets, $outerKeyColumns) {
				foreach ($parentKeySets as $set) {
					$nested->orWhere(array_combine($outerKeyColumns, array_values($set)));
				}
			});
		}
		$this->applyRelationQueryOptions($query);

		if ($this->limit() !== null || $this->offset() !== null) {
			$query = $this->limitedSubquery(
				$query,
				$columns,
				$this->getTargetCollection()->getTable() . '.' . $outerKeyColumns[0]
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

		if ($input === null) {
			if ($operation !== 'create') {
				$this->normalizeOmittedChildren($payload, $this->getCurrentRelationRows($dataSource, $source));
			}

			return $payload;
		}

		$targetCollection = $this->relation->getCollection();
		if ($this->isDetailedPayload($input)) {
			return $this->normalizeDetailedHasRelationPayload($input, $source);
		}

		if (!is_array($input)) {
			$current = $operation === 'create' ? null : ($this->getCurrentRelationRows($dataSource, $source)[0] ?? null);
			$currentId = is_array($current) ? $this->getInputPrimaryKeyValue($targetCollection, $current) : null;
			if ($currentId !== null && $currentId->toUrlId() !== (string) $input) {
				$this->normalizeOmittedChildren($payload, [$current]);
			}
			if ($currentId === null || $currentId->toUrlId() !== (string) $input) {
				$payload['connect'][] = $input;
			}

			return $payload;
		}

		$current = $operation === 'create' ? null : ($this->getCurrentRelationRows($dataSource, $source)[0] ?? null);
		$currentId = is_array($current) ? $this->getInputPrimaryKeyValue($targetCollection, $current) : null;
		$desired = $input;
		$desiredId = $this->getInputPrimaryKeyValue($targetCollection, $desired);

		if ($desiredId === null && $currentId !== null) {
			foreach ($currentId->values() as $fieldName => $value) {
				$desired[$fieldName] = $value;
			}
			$desiredId = $currentId;
		}
		if (
			$currentId !== null
			&& $desiredId !== null
			&& $currentId->toUrlId() !== $desiredId->toUrlId()
		) {
			$this->normalizeOmittedChildren($payload, [$current]);
			$payload['connect'][] = $desiredId;
		}

		$this->applySourceValuesToTargetInput($desired, $source);
		foreach ($this->normalizeRelationItems($input) as $item) {
			$this->getInputPrimaryKeyValue($targetCollection, $desired) === null
				? $payload['create'][] = $desired
				: $payload['update'][] = $desired;
		}

		return $payload;
	}

	public function compileActions(
		MutationQueue $queue,
		MutationStateInterface $source,
		array $actions,
		array $children = []
	): \ON\RestApi\Mutation\MutationTaskInterface|\ON\RestApi\Mutation\MutationDeleteTaskInterface|null {
		foreach ($actions['connect'] ?? [] as $target) {
			$this->compileConnectionUpdate($queue, $source, $target, false);
		}

		foreach ($actions['disconnect'] ?? [] as $target) {
			$this->compileConnectionUpdate($queue, $source, $target, true);
		}

		$this->queueChildMutations($children, $queue);

		return null;
	}

	private function compileConnectionUpdate(
		MutationQueue $queue,
		MutationStateInterface $source,
		mixed $target,
		bool $disconnect
	): void {
		$targetCollection = $this->getTargetCollection();

		$queue->queueUpdate(
			$targetCollection,
			PrimaryKeyCriteria::build($targetCollection, $target),
			$this->connectionUpdatePayload($source, $disconnect)
		);
	}

	private function connectionUpdatePayload(MutationStateInterface $source, bool $disconnect): array
	{
		$payload = [];
		foreach ($this->relation->outerKeys() as $index => $outerKey) {
			$payload[$outerKey] = $disconnect ? null : $source->getValue($this->relation->innerKeys()[$index]);
		}

		return $payload;
	}

	protected function normalizeOmittedChildren(array &$payload, array $currentRows): void
	{
		foreach ($currentRows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$id = $this->getInputPrimaryKeyValue($this->getTargetCollection(), $row);
			if ($id === null) {
				continue;
			}

			if ($this->relation->isCascade() || !$this->relation->isNullable()) {
				$payload['delete'][] = $id->values();
				continue;
			}

			$payload['disconnect'][] = $id;
		}
	}

	protected function normalizeDetailedHasRelationPayload(array $input, MutationStateInterface $source): array
	{
		$payload = $this->normalizeDetailedPayload($input);
		foreach (['create', 'update'] as $mutation) {
			foreach ($payload[$mutation] as $index => $item) {
				if (!is_array($item)) {
					unset($payload[$mutation][$index]);
					continue;
				}

				if ($mutation === 'create') {
					$this->applySourceValuesToTargetInput($item, $source);
				}

				$payload[$mutation][$index] = $item;
			}

			$payload[$mutation] = array_values($payload[$mutation]);
		}

		return $payload;
	}
}
