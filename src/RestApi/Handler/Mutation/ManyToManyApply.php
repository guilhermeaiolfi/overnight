<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Payload\Action\ConnectAction;
use ON\RestApi\Payload\Action\DisconnectAction;
use ON\RestApi\Payload\Node\RelationPayload;
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
trait ManyToManyApply
{
	use RelationApplySupport;

	public function applyRelation(
		MutationQueue $queue,
		MutationStateInterface $source,
		RelationPayload $relation,
		array $children
	): void {
		foreach ($relation->actions as $action) {
			if ($action instanceof DisconnectAction && $action->target !== null) {
				$this->disconnect($queue, $this->getParentIdentityFromSource($source), $action->target);
			}
		}

		foreach ($relation->actions as $action) {
			if ($action instanceof ConnectAction && $action->target !== null) {
				$this->connect($queue, $this->getParentIdentityFromSource($source), $action->target);
			}
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

	private function connect(MutationQueue $queue, PrimaryKeyValue $parentId, mixed $targetId): void
	{
		$through = $this->manyToMany->through;
		$targetIdentity = $targetId instanceof PrimaryKeyValue
			? $targetId
			: PrimaryKeyCriteria::normalize($this->getTargetCollection(), $targetId);
		$payload = [];
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$payload[$through->throughInnerKeys()[$index]] = $parentId->getValue($innerKey);
		}
		foreach ($this->relation->outerKeys() as $index => $outerKey) {
			$payload[$through->throughOuterKeys()[$index]] = $targetIdentity->getValue($outerKey);
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
				new LiteralValue($parentId->getValue($innerKey))
			);
		}
		foreach ($this->relation->outerKeys() as $index => $outerKey) {
			$filters[] = new ComparisonFilter(
				new FieldExpression($through->throughOuterKeys()[$index]),
				ComparisonOperator::Eq,
				new LiteralValue($targetIdentity->getValue($outerKey))
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
}
