<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Parser;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\RelationNode;
use ON\RestApi\Support\MutationInput;

final class DirectusPayloadParser implements PayloadParserInterface
{
	private const OPERATIONS = ['create', 'update', 'delete'];

	public function parse(
		CollectionInterface $collection,
		array $input,
	): RecordNode {
		[$scalars, $relations] = MutationInput::splitNodeInput($collection, $input);

		return new RecordNode(
			collection: $collection,
			fields: $scalars,
			relations: $this->parseRelations($collection, $relations),
		);
	}

	public function parseNode(CollectionInterface $collection, array $input): RecordNode
	{
		[$scalars, $relations] = MutationInput::splitNodeInput($collection, $input);

		return new RecordNode(
			collection: $collection,
			fields: $scalars,
			relations: $this->parseRelations($collection, $relations),
		);
	}

	/**
	 * @param array<string, mixed> $relations
	 * @return array<string, RelationNode>
	 */
	private function parseRelations(CollectionInterface $collection, array $relations): array
	{
		$parsed = [];

		foreach ($relations as $relationName => $rawInput) {
			if (!$collection->relations->has((string) $relationName)) {
				continue;
			}

			$relation = $collection->relations->get((string) $relationName);
			$parsed[(string) $relationName] = new RelationNode(
				relationName: (string) $relationName,
				targetCollection: $relation->getCollection(),
				children: $this->parseRelationInput($relation->getCollection(), $relation->getCardinality() === 'single', $rawInput),
				definition: $relation,
			);
		}

		return $parsed;
	}

	/**
	 * @return list<RecordNode>
	 */
	public function parseRelationInput(CollectionInterface $targetCollection, bool $single, mixed $rawInput): array
	{
		if (is_array($rawInput) && MutationInput::isAssociativeArray($rawInput) && $this->hasOperationPayload($rawInput)) {
			return $this->parseDetailedRelation($targetCollection, $rawInput);
		}

		if ($single) {
			return $rawInput === null ? [] : [$this->parseBasicItem($targetCollection, $rawInput, 0)];
		}

		if (!is_array($rawInput)) {
			return [$this->parseBasicItem($targetCollection, $rawInput, 0)];
		}

		if (MutationInput::isAssociativeArray($rawInput)) {
			return [$this->parseBasicItem($targetCollection, $rawInput, 0)];
		}

		$children = [];
		foreach ($rawInput as $index => $item) {
			$children[] = $this->parseBasicItem($targetCollection, $item, (int) $index);
		}

		return $children;
	}

	/**
	 * @return list<RecordNode>
	 */
	private function parseDetailedRelation(CollectionInterface $targetCollection, array $input): array
	{
		$children = [];

		foreach (self::OPERATIONS as $operation) {
			foreach (MutationInput::normalizeRelationItems($input[$operation] ?? []) as $index => $item) {
				$children[] = $this->parseRelationItem(
					$targetCollection,
					$item,
					$index,
					'explicit',
					match ($operation) {
						'create' => 'create',
						'update' => 'upsert',
						'delete' => 'delete',
					},
				);
			}
		}

		return $children;
	}

	private function parseBasicItem(CollectionInterface $targetCollection, mixed $item, int $index): RecordNode
	{
		return $this->parseRelationItem($targetCollection, $item, $index, 'desired');
	}

	private function parseRelationItem(
		CollectionInterface $targetCollection,
		mixed $item,
		int $index,
		string $intent,
		?string $plannedOperation = null,
	): RecordNode {
		$node = is_array($item)
			? $this->parseNode($targetCollection, $item)
			: new RecordNode($targetCollection);
		$node->relationIntent = $intent;
		$node->relationIndex = $index;
		$node->relationData = $item;
		$node->plannedOperation = $plannedOperation;
		$node->relationMutation = is_array($item);

		return $node;
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
