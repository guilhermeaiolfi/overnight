<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\RelationMutationPayload;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\ComparisonOperator;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\LiteralValue;
use ON\RestApi\Query\Node\LogicalFilter;
use ON\RestApi\Query\Node\LogicalOperator;
use ON\RestApi\Support\PrimaryKeyCriteria;

/**
 * @property M2MRelation $manyToMany
 */
trait ManyToManyMutation
{
	use RelationMutationSupport;

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source
	): RelationMutationPayload {
		$payload = $this->emptyPayload();
		$throughCollection = $this->manyToMany->through->getCollection();
		$targetCollection = $this->getTargetCollection();
		$throughPrimaryKey = $throughCollection->getPrimaryKey()->getFieldNames()[0] ?? 'id';
		$currentRows = $operation === 'create' ? [] : $this->currentPivotRows($source);
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
			$payload->connect[] = $this->linkIntent($input, $targetCollection);

			return $payload;
		}

		if ($this->isDetailedPayload($input)) {
			return $this->normalizeDetailedManyToManyPayload($input, $source);
		}

		$seenPivot = [];
		$seenTarget = [];
		foreach ($input as $item) {
			if (!is_array($item)) {
				$payload->connect[] = $this->linkIntent($item, $targetCollection);
				$seenTarget[(string) $item] = true;
				continue;
			}

			if ($this->isThroughPayload($item)) {
				$pivotId = array_key_exists($throughPrimaryKey, $item) ? $item[$throughPrimaryKey] : null;
				$targetId = $this->extractThroughTargetIdentity($item);

				if ($pivotId !== null && isset($currentByPivotId[(string) $pivotId])) {
					$key = $pivotId instanceof PrimaryKeyValue ? $pivotId->toUrlId() : (string) $pivotId;
					$seenPivot[$key] = true;
					$payload->update[] = $this->childIntent($this->normalizeThroughPayload($source, $item), $throughCollection);
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
					$payload->update[] = $this->childIntent($this->normalizeThroughPayload($source, $item), $throughCollection);
					continue;
				}

				if ($targetId !== null) {
					$seenTarget[$targetId->toUrlId()] = true;
				}
				$payload->create[] = $this->childIntent($this->normalizeThroughPayload($source, $item), $throughCollection);
				continue;
			}

			$targetId = $this->getInputPrimaryKeyValue($targetCollection, $item);
			if ($targetId === null) {
				$payload->create[] = $this->childIntent($item, $targetCollection);
				continue;
			}

			$seenTarget[$targetId->toUrlId()] = true;
			if (isset($currentByTargetId[$targetId->toUrlId()])) {
				$payload->update[] = $this->childIntent($item, $targetCollection);
				continue;
			}

			$payload->connect[] = $this->linkIntent($targetId, $targetCollection);
			if (count($item) > 1) {
				$payload->update[] = $this->childIntent($item, $targetCollection);
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
				$payload->disconnect[] = $this->linkIntent($targetId, $targetCollection);
			}
		}

		return $payload;
	}

	public function applyRelation(
		MutationQueue $queue,
		MutationStateInterface $source,
		RelationMutationPayload $payload,
		array $children
	): void {
		foreach ($payload->disconnect as $target) {
			$this->disconnect($queue, $this->getParentIdentityFromSource($source), self::linkTarget($target));
		}

		foreach ($payload->connect as $target) {
			$this->connect($queue, $this->getParentIdentityFromSource($source), self::linkTarget($target));
		}

		$targetCollection = $this->relation->getCollection();
		foreach ($children['create'] ?? [] as $child) {
			if (!$child instanceof MutationNode || $child->state->getCollection() !== $targetCollection) {
				continue;
			}

			$identity = $this->getPrimaryKeyValueFromState($child->state, false);
			if ($identity === null) {
				continue;
			}

			$this->connect(
				$queue,
				$this->getParentIdentityFromSource($source),
				$identity
			);
		}
	}

	private function normalizeDetailedManyToManyPayload(array $input, MutationStateInterface $source): RelationMutationPayload
	{
		$throughCollection = $this->manyToMany->through->getCollection();
		$payload = $this->normalizeDetailedPayload($input);

		foreach (['create', 'update', 'delete'] as $operation) {
			foreach ($payload->{$operation} as $index => $intent) {
				$item = $intent->data;
				if (!is_array($item) || !$this->isThroughPayload($item)) {
					continue;
				}

				$payload->{$operation}[$index] = $this->childIntent(
					$this->normalizeThroughPayload($source, $item),
					$throughCollection
				);
			}
		}

		return $payload;
	}

	private function normalizeThroughPayload(MutationStateInterface $source, array $item): array
	{
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

	private function currentPivotRows(MutationStateInterface $source): array
	{
		$through = $this->manyToMany->through;
		$fieldValueMap = [];
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$fieldValueMap[$through->throughInnerKeys()[$index]] = $source->resolveValue($source->getValue($innerKey));
		}

		return $this->fetchRowsByFields($through->getCollection(), $fieldValueMap);
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
