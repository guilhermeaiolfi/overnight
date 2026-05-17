<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\ORM\Parser\AbstractNode;
use ON\ORM\Definition\Collection\CollectionInterface;

interface RelationLoaderInterface extends LoaderInterface
{
	public function configureNode(AbstractNode $parent): AbstractNode;

	public function getNode(): AbstractNode;

	/**
	 * @return list<self>
	 */
	public function getChildren(): array;

	/**
	 * @param list<self> $children
	 */
	public function setChildren(array $children): void;

	public function getResponseName(): string;

	public function getTargetCollection(): CollectionInterface;

	public function isSingle(): bool;

	public function getSelectColumns(): array;

	public function getRequestedColumns(): array;

	public function getInternalColumns(): array;

	public function getNestedRelations(): array;
}
