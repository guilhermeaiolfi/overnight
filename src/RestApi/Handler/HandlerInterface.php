<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\AbstractNode;
use ON\ORM\Definition\Collection\CollectionInterface;

interface HandlerInterface
{
	public function prepare(): void;

	public function load(): mixed;

	public function getParent(): ?self;

	public function setParent(?self $parent): void;

	public function addChild(self $child): void;

	/**
	 * @return list<self>
	 */
	public function getChildren(): array;

	public function getCollection(): CollectionInterface;

	public function getTargetCollection(): CollectionInterface;

	public function getRelationName(): ?string;

	public function getResponseName(): string;

	public function getPath(): array;

	public function isSingle(): bool;

	public function configureParserNode(AbstractNode $parent): AbstractNode;

	public function getNode(): AbstractNode;

	public function getSelectColumns(): array;

	public function getRequestedColumns(): array;

	public function getInternalColumns(): array;

	public function getNestedRelations(): array;
}
