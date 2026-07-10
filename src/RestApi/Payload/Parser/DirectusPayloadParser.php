<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Parser;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Payload\Action\BasicRelationAction;
use ON\RestApi\Payload\Action\ConnectAction;
use ON\RestApi\Payload\Action\CreateAction;
use ON\RestApi\Payload\Action\DeleteAction;
use ON\RestApi\Payload\Action\DisconnectAction;
use ON\RestApi\Payload\Action\RelationAction;
use ON\RestApi\Payload\Action\UpdateAction;
use ON\RestApi\Payload\MutationInputMerger;
use ON\RestApi\Payload\Node\MutationNodeSpec;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Payload\Node\RelationPayload;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\PrimaryKeyValue;

final class DirectusPayloadParser implements PayloadParserInterface
{
	private const OPERATIONS = ['create', 'update', 'delete', 'connect', 'disconnect'];

	public function __construct(
		private readonly MutationInputMerger $inputMerger = new MutationInputMerger(),
	) {
	}

	public function parse(
		CollectionInterface $collection,
		array $input,
		string $mode = 'upsert',
		PrimaryKeyValue|string|null $id = null,
		array $files = [],
	): MutationSpec {
		if ($files !== []) {
			$input = $this->inputMerger->mergeFiles($collection, $input, $files);
		}

		[$scalars, $relations] = MutationInput::splitNodeInput($collection, $input);

		return new MutationSpec(new MutationNodeSpec(
			collection: $collection->getName(),
			fields: $scalars,
			relations: $this->parseRelations($collection, $relations),
			operation: $mode,
		));
	}

	public function parseNode(CollectionInterface $collection, array $input): MutationNodeSpec
	{
		[$scalars, $relations] = MutationInput::splitNodeInput($collection, $input);

		return new MutationNodeSpec(
			collection: $collection->getName(),
			fields: $scalars,
			relations: $this->parseRelations($collection, $relations),
		);
	}

	/**
	 * @param array<string, mixed> $relations
	 * @return list<RelationPayload>
	 */
	private function parseRelations(CollectionInterface $collection, array $relations): array
	{
		$parsed = [];

		foreach ($relations as $relationName => $rawInput) {
			if (! $collection->relations->has((string) $relationName)) {
				continue;
			}

			$relation = $collection->relations->get((string) $relationName);
			$parsed[] = new RelationPayload(
				relationName: (string) $relationName,
				targetCollection: $relation->getCollection()->getName(),
				actions: $this->parseRelationInput($relation->getCardinality()->isSingle(), $rawInput),
			);
		}

		return $parsed;
	}

	/**
	 * @return list<RelationAction>
	 */
	public function parseRelationInput(bool $single, mixed $rawInput): array
	{
		if (is_array($rawInput) && MutationInput::isAssociativeArray($rawInput) && $this->hasOperationPayload($rawInput)) {
			return $this->parseDetailedRelation($rawInput);
		}

		if ($single) {
			return [new BasicRelationAction(item: $rawInput)];
		}

		if (! is_array($rawInput)) {
			return [new BasicRelationAction(items: [$rawInput])];
		}

		if (MutationInput::isAssociativeArray($rawInput)) {
			return [new BasicRelationAction(items: [$rawInput])];
		}

		return [new BasicRelationAction(items: $rawInput)];
	}

	/**
	 * @return list<RelationAction>
	 */
	private function parseDetailedRelation(array $input): array
	{
		$actions = [];

		foreach (self::OPERATIONS as $operation) {
			foreach (MutationInput::normalizeRelationItems($input[$operation] ?? []) as $index => $item) {
				$actions[] = $this->parseDetailedItem($operation, $item, $index);
			}
		}

		return $actions;
	}

	private function parseDetailedItem(string $operation, mixed $item, int $index): RelationAction
	{
		return match ($operation) {
			'create' => new CreateAction(
				data: is_array($item) ? $item : [],
				index: $index,
				explicitOperation: true,
			),
			'update' => new UpdateAction(
				data: is_array($item) ? $item : [],
				index: $index,
				explicitOperation: true,
			),
			'delete' => new DeleteAction(
				data: is_array($item) ? $item : null,
				target: is_array($item) ? null : $item,
				index: $index,
				explicitOperation: true,
			),
			'connect' => new ConnectAction(
				target: $item,
				data: is_array($item) ? $item : null,
				index: $index,
				explicitOperation: true,
			),
			'disconnect' => new DisconnectAction(
				target: $item,
				index: $index,
				explicitOperation: true,
			),
		};
	}

	private function hasOperationPayload(array $input): bool
	{
		foreach (self::OPERATIONS as $key) {
			if (array_key_exists($key, $input)) {
				return true;
			}
		}

		return false;
	}
}
