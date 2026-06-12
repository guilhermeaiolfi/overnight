<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Mapper\Representation\StorageRepresentation;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Payload\Action\CreateAction as PayloadCreateAction;
use ON\RestApi\Payload\Action\DeleteAction as PayloadDeleteAction;
use ON\RestApi\Payload\Action\UpdateAction as PayloadUpdateAction;
use ON\RestApi\Payload\Node\MutationNodeSpec;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Payload\Node\RelationPayload;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Support\PrimaryKeyCriteria;

final class MutationNodeBuilder
{
	private const CHILD_ACTIONS = ['create', 'update', 'delete'];

	public static function fromSpec(
		MutationSpec $spec,
		string $mode,
		Registry $registry,
		ItemRepositoryInterface $items,
		HandlerFactory $handlers,
		PrimaryKeyValue|string|null $id = null,
		array $path = [],
	): ?MutationNode {
		$collection = $registry->getCollection($spec->root->collection);
		$rootState = new MutationState($collection, $spec->root->fields);
		$state = $path === [] ? $rootState : new MutationState($collection, $spec->root->fields);

		if ($path === []) {
			$state->setData($spec->root->fields);
		}

		$resolvedId = $id;
		if ($resolvedId === null && $mode !== 'create') {
			$resolvedId = $collection->getPrimaryKey()->extractFromInput($spec->root->fields);
		}
		$resolvedId = $resolvedId === null ? null : $collection->getPrimaryKey()->getValue($resolvedId);
		$parentOperation = self::resolveOperation($items, $mode, $collection, $resolvedId);
		if ($parentOperation === 'update' && $resolvedId !== null) {
			foreach ($resolvedId->values() as $fieldName => $value) {
				$state->setValue($fieldName, $value);
			}
		}
		self::assertCreateIdAvailable($items, $parentOperation, $collection, $state);
		$state->setData($spec->root->fields);

		return self::nodeFromSpec($registry, $items, $handlers, $spec->root, $mode, $state, $id, $path);
	}

	private static function nodeFromSpec(
		Registry $registry,
		ItemRepositoryInterface $items,
		HandlerFactory $handlers,
		MutationNodeSpec $spec,
		string $mode,
		MutationStateInterface $state,
		PrimaryKeyValue|string|null $id,
		array $path,
		?MutationStateInterface $parentState = null,
	): ?MutationNode {
		$collection = $state->getCollection();
		$fields = $parentState === null
			? $spec->fields
			: $parentState->rebindValueRefs($spec->fields);
		$state->setData($fields);
		if ($id === null && $mode !== 'create') {
			$id = $collection->getPrimaryKey()->extractFromInput($spec->fields);
		}
		$id = $id === null ? null : $collection->getPrimaryKey()->getValue($id);
		$operation = self::resolveOperation($items, $mode, $collection, $id);
		if ($operation === 'update' && $id === null) {
			return null;
		}
		if ($operation === 'update') {
			foreach ($id->values() as $fieldName => $value) {
				$state->setValue($fieldName, $value);
			}
		}
		self::assertCreateIdAvailable($items, $operation, $collection, $state);
		$relations = [];
		foreach ($spec->relations as $relationPayload) {
			$relationNode = self::relationFromPayload($registry, $items, $handlers, $relationPayload, $state, $path);
			if ($relationNode !== null) {
				$relations[$relationPayload->relationName] = $relationNode;
			}
		}

		return new MutationNode($operation, $collection, $state, $path, $relations);
	}

	private static function relationFromPayload(
		Registry $registry,
		ItemRepositoryInterface $items,
		HandlerFactory $handlers,
		RelationPayload $relationPayload,
		MutationStateInterface $state,
		array $path
	): ?RelationNode {
		$collection = $state->getCollection();
		$handler = $handlers->mutation($collection, $relationPayload->relationName);
		if ($handler === null) {
			return null;
		}
		$relationPath = [...$path, $relationPayload->relationName];
		$children = ['create' => [], 'update' => [], 'delete' => []];
		foreach ($relationPayload->actions as $action) {
			$child = match (true) {
				$action instanceof PayloadCreateAction => self::entityActionNode($registry, $items, $handlers, $action, 'create', $state, $relationPath),
				$action instanceof PayloadUpdateAction => self::entityActionNode($registry, $items, $handlers, $action, 'update', $state, $relationPath),
				$action instanceof PayloadDeleteAction => self::deleteActionNode($action, $registry, $relationPath),
				default => null,
			};
			if ($child !== null) {
				$children[$child->operation][] = $child;
			}
		}

		return new RelationNode($handler, $relationPayload, $state, $relationPath, $children);
	}

	private static function entityActionNode(
		Registry $registry,
		ItemRepositoryInterface $items,
		HandlerFactory $handlers,
		PayloadCreateAction|PayloadUpdateAction $action,
		string $operation,
		MutationStateInterface $state,
		array $path
	): ?MutationNode {
		if ($action->node === null) {
			return null;
		}
		$collection = $registry->getCollection($action->collection ?? $action->node->collection);
		$childState = new MutationState($collection, []);

		return match ($operation) {
			'create' => self::nodeFromSpec($registry, $items, $handlers, $action->node, 'create', $childState, null, $path, $state),
			'update' => self::nodeFromSpec(
				$registry,
				$items,
				$handlers,
				$action->node,
				'upsert',
				$childState,
				$collection->getPrimaryKey()->extractFromInput($action->node->fields),
				$path,
				$state,
			),
			default => null,
		};
	}

	private static function deleteActionNode(PayloadDeleteAction $action, Registry $registry, array $path): ?MutationNode
	{
		if ($action->collection === null) {
			return null;
		}
		$collection = $registry->getCollection($action->collection);
		$item = $action->data ?? PrimaryKeyCriteria::normalize($collection, $action->target)->values();

		return self::deleteChildNode($collection, $item, $path);
	}

	private static function deleteChildNode(CollectionInterface $collection, array $item, array $path): ?MutationNode
	{
		$id = $collection->getPrimaryKey()->extractFromInput($item);
		if ($id === null) {
			return null;
		}

		return new MutationNode('delete', $collection, new MutationState($collection, $collection->getPrimaryKey()->getValue($id)->values()), $path);
	}

	private static function resolveOperation(ItemRepositoryInterface $items, string $mode, CollectionInterface $collection, ?PrimaryKeyValue $id): string
	{
		if ($mode !== 'upsert') {
			return $mode;
		}

		return $id !== null && $items->findByIdentity($collection, $id, StorageRepresentation::class) !== null ? 'update' : 'create';
	}

	private static function assertCreateIdAvailable(ItemRepositoryInterface $items, string $operation, CollectionInterface $collection, MutationStateInterface $state): void
	{
		if ($operation !== 'create') {
			return;
		}

		$createId = $collection->getPrimaryKey()->extractFromInput($state->getData());
		if (
			$createId !== null
			&& ! array_filter($createId->values(), static fn (mixed $value): bool => $value instanceof ValueRef)
			&& $items->findByIdentity($collection, $createId, StorageRepresentation::class) !== null
		) {
			throw new RestApiError(
				"A record with this " . implode(', ', $collection->getPrimaryKey()->getFieldNames()) . " already exists.",
				'DUPLICATE',
				$collection->getPrimaryKey()->getFieldNames()[0] ?? null,
				409
			);
		}
	}

}
