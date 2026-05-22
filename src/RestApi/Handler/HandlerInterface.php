<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\AbstractNode;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\MutationTaskInterface;
use ON\RestApi\Resolver\DataSourceInterface;

interface HandlerInterface extends LoaderInterface
{
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

	public function mutationCollection(string $operation, mixed $item): CollectionInterface;

	public function inputPrimaryKeyValue(CollectionInterface $collection, array $input): mixed;

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source,
		DataSourceInterface $dataSource
	): array;

	public function compileCreate(
		array $payload,
		MutationStateInterface $source,
		array $children,
		MutationQueue $queue
	): void;

	public function compileUpdate(
		array $payload,
		MutationStateInterface $source,
		array $children,
		MutationQueue $queue
	): void;

	public function compileDelete(
		array $payload,
		MutationStateInterface $source,
		array $children,
		MutationQueue $queue
	): void;

	public function compileConnect(mixed $target, MutationStateInterface $source, MutationQueue $queue): void;

	public function compileDisconnect(mixed $target, MutationStateInterface $source, MutationQueue $queue): void;

	public function compileRootAction(
		string $operation,
		MutationStateInterface $state,
		MutationQueue $queue
	): MutationTaskInterface|MutationDeleteTaskInterface|null;
}
