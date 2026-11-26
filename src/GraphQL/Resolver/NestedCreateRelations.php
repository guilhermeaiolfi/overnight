<?php

declare(strict_types=1);

namespace ON\GraphQL\Resolver;

use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\GraphQL\Error\GraphQLUserError;

/** Relation shapes the built-in resolvers can write by setting the child's foreign key. */
final class NestedCreateRelations
{
	public static function supports(RelationInterface $relation): bool
	{
		return ($relation instanceof HasOneRelation || $relation instanceof HasManyRelation)
			&& ! ($relation instanceof FirstOfManyRelation)
			&& ! $relation->isJunction()
			&& ! $relation->getKeyPairing()->isComposite();
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, RelationInterface>
	 */
	public static function resolve(Collection $collection, array $input): array
	{
		$relations = [];
		foreach (array_keys($input) as $name) {
			$relation = $collection->getRelation((string) $name);
			if ($relation === null || ! self::supports($relation) || $relation->getCollection()->isHidden()) {
				throw new GraphQLUserError('Nested create is not supported for this relation.', 'INVALID_INPUT', (string) $name);
			}

			$relations[$name] = $relation;
		}

		return $relations;
	}
}
