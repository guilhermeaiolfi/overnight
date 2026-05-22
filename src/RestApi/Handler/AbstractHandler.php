<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\AbstractNode;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\FieldInterface;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\MutationTaskInterface;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\ComparisonOperator;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\LiteralValue;

abstract class AbstractHandler implements HandlerInterface
{
	private ?HandlerInterface $parent = null;

	/** @var list<HandlerInterface> */
	private array $children = [];

	private ?AbstractNode $node = null;

	public function __construct(
		private CollectionInterface $collection,
		private string $responseName,
		private ?string $relationName = null
	) {
	}

	public function prepare(): void
	{
	}

	public function getParent(): ?HandlerInterface
	{
		return $this->parent;
	}

	public function setParent(?HandlerInterface $parent): void
	{
		$this->parent = $parent;
	}

	public function addChild(HandlerInterface $child): void
	{
		$child->setParent($this);
		$this->children[] = $child;
	}

	public function getChildren(): array
	{
		return $this->children;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getRelationName(): ?string
	{
		return $this->relationName;
	}

	public function getResponseName(): string
	{
		return $this->responseName;
	}

	public function getPath(): array
	{
		$parentPath = $this->parent?->getPath() ?? [];
		if ($this->relationName === null) {
			return $parentPath;
		}

		return [...$parentPath, $this->responseName];
	}

	public function getNode(): AbstractNode
	{
		if ($this->node === null) {
			throw new \LogicException('Handler parser node has not been configured.');
		}

		return $this->node;
	}

	protected function setNode(AbstractNode $node): void
	{
		$this->node = $node;
	}

	public function compileCreate(
		array $payload,
		MutationStateInterface $source,
		array $children,
		MutationQueue $queue
	): void {
	}

	public function compileUpdate(
		array $payload,
		MutationStateInterface $source,
		array $children,
		MutationQueue $queue
	): void {
	}

	public function compileDelete(
		array $payload,
		MutationStateInterface $source,
		array $children,
		MutationQueue $queue
	): void {
	}

	public function compileConnect(mixed $target, MutationStateInterface $source, MutationQueue $queue): void
	{
	}

	public function compileDisconnect(mixed $target, MutationStateInterface $source, MutationQueue $queue): void
	{
	}

	public function compileRootAction(
		string $operation,
		MutationStateInterface $state,
		MutationQueue $queue
	): MutationTaskInterface|MutationDeleteTaskInterface|null {
		$collection = $state->getCollection();

		if ($operation === 'create') {
			return $queue->queueInsert($state);
		}

		if ($operation === 'update') {
			return $queue->queueUpdate(
				$collection,
				$this->primaryKeyCriteria($collection, $state->getValue($this->getPrimaryKeyName($collection))),
				$state
			);
		}

		if ($operation === 'delete') {
			return $queue->queueDelete(
				$collection,
				$this->primaryKeyCriteria($collection, $state->getValue($this->getPrimaryKeyName($collection)))
			);
		}

		return null;
	}

	private function primaryKeyCriteria(CollectionInterface $collection, mixed $id): ComparisonFilter
	{
		return new ComparisonFilter(
			new FieldExpression($this->getPrimaryKeyName($collection)),
			ComparisonOperator::Eq,
			new LiteralValue($id)
		);
	}

	private function getPrimaryKeyName(CollectionInterface $collection): string
	{
		$pk = $collection->getPrimaryKey();

		if ($pk instanceof FieldInterface) {
			return $pk->getName();
		}

		if (is_array($pk) && isset($pk[0]) && $pk[0] instanceof FieldInterface) {
			return $pk[0]->getName();
		}

		return 'id';
	}
}
