<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;

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
		MutationStateInterface $source
	): array {
		$payload = parent::normalizePayload($operation, $input, $source);
		if (!is_array($input)) {
			return $payload;
		}

		$targetCollection = $this->relation->getCollection();
		if ($this->isAssociativeArray($input) && $this->hasOperationPayload($input)) {
			foreach (['create', 'update'] as $operation) {
				foreach ($this->normalizeRelationItems($input[$operation] ?? []) as $item) {
					if (!is_array($item)) {
						continue;
					}

					$item[$this->relation->getOuterField()->getName()] = $source->getValue($this->relation->getInnerField()->getName());
					$payload[$operation][] = $item;
				}
			}

			foreach ($this->normalizeRelationItems($input['connect'] ?? []) as $targetId) {
				$payload['update'][] = [
					$this->getPrimaryKeyName($targetCollection) => $targetId,
					$this->relation->getOuterField()->getName() => $source->getValue($this->relation->getInnerField()->getName()),
				];
			}

			foreach ($this->normalizeRelationItems($input['disconnect'] ?? []) as $targetId) {
				$payload['update'][] = [
					$this->getPrimaryKeyName($targetCollection) => $targetId,
					$this->relation->getOuterField()->getName() => null,
				];
			}

			$payload['delete'] = $this->normalizeRelationItems($input['delete'] ?? []);

			return $payload;
		}

		foreach ($this->normalizeRelationItems($input) as $item) {
			if (!is_array($item)) {
				continue;
			}

			$item[$this->relation->getOuterField()->getName()] = $source->getValue($this->relation->getInnerField()->getName());
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
	}

	protected function hasOperationPayload(array $input): bool
	{
		foreach (['create', 'update', 'delete', 'connect', 'disconnect'] as $key) {
			if (array_key_exists($key, $input)) {
				return true;
			}
		}

		return false;
	}
}
