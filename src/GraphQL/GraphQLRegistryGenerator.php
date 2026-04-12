<?php

declare(strict_types=1);

namespace ON\GraphQL;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Field\Field;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\HasManyRelation;
use ON\ORM\Definition\Relation\HasOneRelation;
use ON\ORM\Definition\Relation\RelationInterface;
use Psr\Container\ContainerInterface;

class GraphQLRegistryGenerator
{
	protected Registry $registry;
	protected array $types = [];
	protected array $typeFieldsMap = [];

	public function __construct(
		protected Registry $ormRegistry,
		protected ?ContainerInterface $container = null
	) {
	}

	public function generate(): Schema
	{
		$this->buildTypes();
		$queryFields = $this->buildQueryFields();
		$mutationFields = $this->buildMutationFields();

		$queryType = new ObjectType([
			'name' => 'Query',
			'fields' => $queryFields,
		]);

		$hasMutationResolvers = $this->hasAnyMutationResolvers();
		$mutationType = ! empty($mutationFields) && $hasMutationResolvers
			? new ObjectType(['name' => 'Mutation', 'fields' => $mutationFields])
			: null;

		return new Schema([
			'query' => $queryType,
			'mutation' => $mutationType,
			'typeLoader' => fn (string $name) => $this->types[$name] ?? null,
		]);
	}

	protected function hasAnyMutationResolvers(): bool
	{
		foreach ($this->ormRegistry->getCollections() as $collection) {
			if ($collection->metadata('gql::resolver::create') !== null
				|| $collection->metadata('gql::resolver::update') !== null
				|| $collection->metadata('gql::resolver::delete') !== null
			) {
				return true;
			}
		}

		return false;
	}

	protected function buildTypes(): void
	{
		foreach ($this->ormRegistry->getCollections() as $collection) {
			if ($collection->hidden) {
				continue;
			}

			$typeName = $this->getTypeName($collection->name);
			$fields = [];

			foreach ($collection->fields as $fieldName => $field) {
				$fields[$fieldName] = $this->convertField($field);
			}

			foreach ($collection->relations as $relationName => $relation) {
				$fields[$relationName] = $this->convertRelation($relation);
			}

			$this->typeFieldsMap[$typeName] = $fields;
		}

		foreach ($this->typeFieldsMap as $typeName => $fields) {
			$this->types[$typeName] = new ObjectType([
				'name' => $typeName,
				'fields' => $fields,
			]);
		}
	}

	protected function getTypeName(string $collectionName): string
	{
		return ucfirst($collectionName);
	}

	protected function convertField(Field $field): array
	{
		$graphqlType = $this->mapOrmTypeToGraphQL($field);

		$overrideType = $field->metadata('gql::type');
		if ($overrideType !== null) {
			$graphqlType = $overrideType;
		}

		$resolver = $field->metadata('gql::resolver');

		return [
			'type' => $graphqlType,
			'description' => $field->getColumn(),
			'resolve' => $resolver !== null
				? $this->wrapResolver($resolver)
				: fn ($source) => $source->{$field->getColumn()} ?? null,
		];
	}

	protected function convertRelation(RelationInterface $relation): array
	{
		$targetType = $this->getTypeName($relation->getCollection());

		if ($relation instanceof HasOneRelation) {
			$type = $this->types[$targetType] ?? Type::string();
		} elseif ($relation instanceof HasManyRelation) {
			$type = Type::listOf($this->types[$targetType] ?? Type::string());
		} else {
			$type = Type::string();
		}

		$resolver = $relation->metadata('gql::resolver');

		return [
			'type' => $type,
			'resolve' => $resolver !== null
				? $this->wrapResolver($resolver)
				: fn ($source) => $this->resolveRelation($source, $relation),
		];
	}

	protected function wrapResolver(callable $resolver): callable
	{
		return function ($source, array $args = [], ?object $context = null) use ($resolver) {
			if ($this->container !== null) {
				return $resolver($source, $args, $this->container, $context);
			}

			return $resolver($source, $args, null, $context);
		};
	}

	protected function resolveRelation($source, RelationInterface $relation): mixed
	{
		$property = $relation->getInnerKey();
		$value = $source->{$property} ?? null;

		if ($relation instanceof HasManyRelation && is_array($value)) {
			return $value;
		}

		return $value;
	}

	protected function mapOrmTypeToGraphQL(Field $field): Type|string
	{
		$type = $field->getType();
		$isNonNull = ! $field->isNullable();

		$graphqlType = match ($type) {
			'string', 'text', 'varchar', 'char' => 'String',
			'int', 'integer', 'smallint', 'bigint' => 'Int',
			'bool', 'boolean' => 'Boolean',
			'float', 'double', 'decimal' => 'Float',
			'datetime', 'date', 'time', 'timestamp' => 'String',
			'id', 'uuid' => 'ID',
			default => 'String',
		};

		if ($isNonNull) {
			$graphqlType .= '!';
		}

		return $graphqlType;
	}

	protected function buildQueryFields(): array
	{
		$fields = [];

		foreach ($this->ormRegistry->getCollections() as $collection) {
			if ($collection->hidden) {
				continue;
			}

			$typeName = $this->getTypeName($collection->name);
			$type = $this->types[$typeName] ?? Type::string();

			$findAllResolver = $collection->metadata('gql::resolver::findAll');
			$fields[$collection->name] = [
				'type' => Type::listOf($type),
				'resolve' => $findAllResolver !== null
					? $this->wrapResolver($findAllResolver)
					: fn ($root, $args) => $this->resolveCollection($collection),
			];

			$findByIdResolver = $collection->metadata('gql::resolver::findById');
			$fields[$collection->name . '_by_id'] = [
				'type' => $type,
				'args' => ['id' => Type::nonNull(Type::id())],
				'resolve' => $findByIdResolver !== null
					? $this->wrapResolver($findByIdResolver)
					: fn ($root, $args) => $this->resolveById($collection, $args['id']),
			];
		}

		return $fields;
	}

	protected function buildMutationFields(): array
	{
		$fields = [];

		foreach ($this->ormRegistry->getCollections() as $collection) {
			if ($collection->hidden) {
				continue;
			}

			$typeName = $this->getTypeName($collection->name);
			$type = $this->types[$typeName] ?? Type::string();

			$createResolver = $collection->metadata('gql::resolver::create');
			if ($createResolver !== null) {
				$fields['create_' . $collection->name] = [
					'type' => $type,
					'args' => ['input' => Type::nonNull(Type::string())],
					'resolve' => $this->wrapResolver($createResolver),
				];
			}

			$updateResolver = $collection->metadata('gql::resolver::update');
			if ($updateResolver !== null) {
				$fields['update_' . $collection->name] = [
					'type' => $type,
					'args' => [
						'id' => Type::nonNull(Type::id()),
						'input' => Type::nonNull(Type::string()),
					],
					'resolve' => $this->wrapResolver($updateResolver),
				];
			}

			$deleteResolver = $collection->metadata('gql::resolver::delete');
			if ($deleteResolver !== null) {
				$fields['delete_' . $collection->name] = [
					'type' => Type::boolean(),
					'args' => ['id' => Type::nonNull(Type::id())],
					'resolve' => $this->wrapResolver($deleteResolver),
				];
			}
		}

		return $fields;
	}

	protected function resolveCollection(Collection $collection): array
	{
		throw new \RuntimeException(
			\sprintf(
				'Resolver for collection "%s::findAll" is not defined. Use ->metadata("gql::resolver::findAll", callable) to define it.',
				$collection->name
			)
		);
	}

	protected function resolveById(Collection $collection, string $id): ?object
	{
		throw new \RuntimeException(
			\sprintf(
				'Resolver for collection "%s::findById" is not defined. Use ->metadata("gql::resolver::findById", callable) to define it.',
				$collection->name
			)
		);
	}

	protected function resolveCreate(Collection $collection, array $input): ?object
	{
		throw new \RuntimeException(
			\sprintf(
				'Resolver for collection "%s::create" is not defined. Use ->metadata("gql::resolver::create", callable) to define it.',
				$collection->name
			)
		);
	}

	protected function resolveUpdate(Collection $collection, string $id, array $input): ?object
	{
		throw new \RuntimeException(
			\sprintf(
				'Resolver for collection "%s::update" is not defined. Use ->metadata("gql::resolver::update", callable) to define it.',
				$collection->name
			)
		);
	}

	protected function resolveDelete(Collection $collection, string $id): bool
	{
		throw new \RuntimeException(
			\sprintf(
				'Resolver for collection "%s::delete" is not defined. Use ->metadata("gql::resolver::delete", callable) to define it.',
				$collection->name
			)
		);
	}
}
