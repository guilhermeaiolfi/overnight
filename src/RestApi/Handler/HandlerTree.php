<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\AbstractNode;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\RelationSelection;

final class HandlerTree
{
	public function __construct(
		private RootHandler $root
	) {
	}

	public function root(): RootHandler
	{
		return $this->root;
	}

	public function load(): array
	{
		$this->configureParserTree($this->root->getChildren(), $this->root->rootNode());
		$this->root->parseRows();
		$this->loadRelations($this->root->getChildren());

		return $this->root->result();
	}

	public function includeQueryRelations(
		array $relations,
		QueryContext $context,
		HandlerFactory $factory
	): self {
		$this->attachQueryChildren($this->root, $this->root->getCollection(), $relations, $context, $factory);

		return $this;
	}

	public function includeMutationInput(
		array $input,
		HandlerFactory $factory
	): self {
		$this->attachMutationChildren($this->root, $this->root->getCollection(), $input, $factory);

		return $this;
	}

	private function attachQueryChildren(
		HandlerInterface $parent,
		CollectionInterface $collection,
		array $relations,
		QueryContext $context,
		HandlerFactory $factory
	): void {
		foreach ($relations as $selection) {
			if (!$selection instanceof RelationSelection) {
				continue;
			}

			$handler = $factory->relation($collection, $selection, $context);
			if ($handler === null) {
				continue;
			}

			$handler = $this->rememberChild($parent, $handler);
			$this->attachQueryChildren(
				$handler,
				$handler->getTargetCollection(),
				$handler->getNestedRelations(),
				$context,
				$factory
			);
		}
	}

	private function attachMutationChildren(
		HandlerInterface $parent,
		CollectionInterface $collection,
		array $input,
		HandlerFactory $factory
	): void {
		$relationInputs = $this->relationInputs($collection, $input);
		foreach ($relationInputs as $relationName => $relationInput) {
			$handler = $this->findChild($parent, $collection->getName(), $relationName)
				?? $factory->mutation($collection, $relationName);
			if ($handler === null) {
				continue;
			}

			$handler = $this->rememberChild($parent, $handler);

			foreach ($this->nestedMutationInputs($handler, $relationInput) as [$childCollection, $childInput]) {
				$this->attachMutationChildren($handler, $childCollection, $childInput, $factory);
			}
		}
	}

	private function rememberChild(HandlerInterface $parent, HandlerInterface $handler): HandlerInterface
	{
		$existing = $this->findChild(
			$parent,
			$handler->getCollection()->getName(),
			(string) $handler->getRelationName(),
			$handler->getResponseName()
		);
		if ($existing !== null) {
			return $existing;
		}

		$parent->addChild($handler);

		return $handler;
	}

	private function findChild(
		HandlerInterface $parent,
		string $collectionName,
		string $relationName,
		?string $responseName = null
	): ?HandlerInterface
	{
		foreach ($parent->getChildren() as $child) {
			if (
				$child->getCollection()->getName() === $collectionName
				&& $child->getRelationName() === $relationName
				&& ($responseName === null || $child->getResponseName() === $responseName)
			) {
				return $child;
			}
		}

		return null;
	}

	private function relationInputs(CollectionInterface $collection, array $input): array
	{
		$relations = [];
		foreach ($input as $key => $value) {
			if ($collection->relations->has((string) $key)) {
				$relations[(string) $key] = $value;
			}
		}

		return $relations;
	}

	private function nestedMutationInputs(HandlerInterface $handler, mixed $relationInput): array
	{
		if (!is_array($relationInput)) {
			return [];
		}

		$items = [];
		if ($this->isDetailedPayload($relationInput)) {
			foreach (['create', 'update'] as $operation) {
				foreach ($this->relationItems($relationInput[$operation] ?? []) as $item) {
					if (is_array($item)) {
						$items[] = [$handler->mutationCollection($operation, $item), $item];
					}
				}
			}

			return $items;
		}

		foreach ($this->relationItems($relationInput) as $item) {
			if (is_array($item)) {
				$operation = $handler->inputPrimaryKeyValue($handler->getTargetCollection(), $item) === null ? 'create' : 'update';
				$items[] = [$handler->mutationCollection($operation, $item), $item];
			}
		}

		return $items;
	}

	private function relationItems(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		return $this->isAssociativeArray($value) ? [$value] : $value;
	}

	private function isDetailedPayload(array $input): bool
	{
		foreach (['create', 'update', 'delete', 'connect', 'disconnect'] as $key) {
			if (array_key_exists($key, $input)) {
				return true;
			}
		}

		return false;
	}

	private function isAssociativeArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}

		return array_keys($value) !== range(0, count($value) - 1);
	}

	private function configureParserTree(array $handlers, AbstractNode $parent): void
	{
		foreach ($handlers as $handler) {
			$node = $handler->configureParserNode($parent);
			$handler->prepare();
			$this->configureParserTree($handler->getChildren(), $node);
		}
	}

	private function loadRelations(array $handlers): void
	{
		foreach ($handlers as $handler) {
			$handler->load();
			$this->loadRelations($handler->getChildren());
		}
	}
}
