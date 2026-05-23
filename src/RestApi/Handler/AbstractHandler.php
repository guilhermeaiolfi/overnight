<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\AbstractNode;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;

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

}
