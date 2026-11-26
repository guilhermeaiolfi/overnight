<?php

declare(strict_types=1);

namespace ON\GraphQL\Resolver;

use LogicException;
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
		if ((! $relation instanceof HasOneRelation && ! $relation instanceof HasManyRelation)
			|| $relation instanceof FirstOfManyRelation
			|| $relation->isJunction()
		) {
			return false;
		}

		try {
			return ! $relation->getKeyPairing()->isComposite();
		} catch (LogicException) {
			// Custom read resolvers can deliberately omit ORM keys.
			return false;
		}
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
