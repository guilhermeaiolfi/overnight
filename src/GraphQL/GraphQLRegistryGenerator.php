<?php

declare(strict_types=1);

namespace ON\GraphQL;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use ON\GraphQL\Resolver\GraphQLResolverInterface;
use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Field\Field;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\HasManyRelation;
use ON\ORM\Definition\Relation\HasOneRelation;
use ON\ORM\Definition\Relation\RelationInterface;
use Psr\Container\ContainerInterface;

class GraphQLRegistryGenerator
{
	protected array $types = [];
	protected array $inputTypes = [];

	public function __construct(
		protected Registry $ormRegistry,
		protected ?ContainerInterface $container = null,
		protected ?GraphQLResolverInterface $resolver = null
	) {
	}

	public function generate(): Schema
	{
		$this->types = [];
		$this->inputTypes = [];

		$this->buildTypes();
		$queryFields = $this->buildQueryFields();
		$mutationFields = $this->buildMutationFields();

		$queryType = new ObjectType([
			'name' => 'Query',
			'description' => 'Root query type',
			'fields' => $queryFields,
		]);

		$mutationType = !empty($mutationFields)
			? new ObjectType([
				'name' => 'Mutation',
				'description' => 'Root mutation type',
				'fields' => $mutationFields,
			])
			: null;

		$this->types['Query'] = $queryType;
		if ($mutationType !== null) {
			$this->types['Mutation'] = $mutationType;
		}

		return new Schema([
			'query' => $queryType,
			'mutation' => $mutationType,
			'typeLoader' => fn (string $name) => $this->types[$name] ?? $this->inputTypes[$name] ?? null,
		]);
	}

	protected function buildTypes(): void
	{
		foreach ($this->ormRegistry->getCollections() as $collection) {
			if ($collection->isHidden()) {
				continue;
			}
			$typeName = $this->getTypeName($collection->getName());
			$this->types[$typeName] = new ObjectType([
				'name' => $typeName,
				'fields' => fn() => $this->buildFieldsForCollection($collection),
			]);
		}
	}

	protected function buildFieldsForCollection(Collection $collection): array
	{
		$fields = [];

		foreach ($collection->fields as $fieldName => $field) {
			$fields[$fieldName] = $this->convertField($field);
		}

		foreach ($collection->relations as $relationName => $relation) {
			$fields[$relationName] = $this->convertRelation($relation);
		}

		return $fields;
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
		$targetTypeObj = $this->types[$targetType] ?? null;

		if ($targetTypeObj === null) {
			$targetTypeObj = $this->buildFallbackType($relation->getCollection());
			$this->types[$targetType] = $targetTypeObj;
		}

		if ($relation instanceof HasOneRelation) {
			$type = $targetTypeObj;
		} elseif ($relation instanceof HasManyRelation) {
			$type = Type::listOf($targetTypeObj);
		} else {
			$type = Type::string();
		}

		$resolver = $relation->metadata('gql::resolver');

		return [
			'type' => $type,
			'resolve' => $resolver !== null
				? $this->wrapResolver($resolver)
				: function ($source, $args, $context = null, $info = null) use ($relation) {
					if ($this->resolver !== null) {
						return $this->resolver->resolveRelation($source, $relation);
					}
					// Fallback: property access
					$property = $relation->getInnerKey();
					$value = $source->{$property} ?? null;
					if ($relation instanceof HasOneRelation) {
						return is_array($value) ? ($value[0] ?? null) : $value;
					}
					if ($relation instanceof HasManyRelation && is_array($value)) {
						return $value;
					}
					return $value;
				},
		];
	}

	protected function buildFallbackType(string $collectionName): ObjectType
	{
		$collection = $this->ormRegistry->getCollection($collectionName);
		$typeName = $this->getTypeName($collectionName);

		if (!$collection) {
			return new ObjectType(['name' => $typeName, 'fields' => []]);
		}

		return new ObjectType([
			'name' => $typeName,
			'fields' => fn() => $this->buildFieldsForCollection($collection),
		]);
	}

	protected function wrapResolver(callable $resolver, bool $includeSource = true): callable
	{
		return function ($source, array $args = [], ?object $context = null) use ($resolver, $includeSource) {
			$params = $includeSource ? [$source, $args] : [$args];
			$params[] = $this->container;
			$params[] = $context;

			return $resolver(...$params);
		};
	}

	protected function mapOrmTypeToGraphQL(Field $field): Type|string
	{
		$type = $field->getType();
		$isNonNull = ! $field->isNullable();

		$graphqlType = match ($type) {
			'string', 'text', 'varchar', 'char' => Type::string(),
			'int', 'integer', 'smallint', 'bigint' => Type::int(),
			'bool', 'boolean' => Type::boolean(),
			'float', 'double', 'decimal' => Type::float(),
			'datetime', 'date', 'time', 'timestamp' => Type::string(),
			'id', 'uuid' => Type::id(),
			default => Type::string(),
		};

		if ($isNonNull) {
			$graphqlType = Type::nonNull($graphqlType);
		}

		return $graphqlType;
	}

	protected function buildConnectionType(string $typeName, ObjectType $itemType): ObjectType
	{
		$connectionTypeName = $typeName . 'Connection';

		if (isset($this->types[$connectionTypeName])) {
			return $this->types[$connectionTypeName];
		}

		$connectionType = new ObjectType([
			'name' => $connectionTypeName,
			'description' => "Paginated list of {$typeName} items",
			'fields' => [
				'items' => [
					'type' => Type::listOf($itemType),
					'description' => 'The list of items',
				],
				'totalCount' => [
					'type' => Type::nonNull(Type::int()),
					'description' => 'Total number of matching items',
				],
			],
		]);

		$this->types[$connectionTypeName] = $connectionType;

		return $connectionType;
	}

	protected function buildQueryFields(): array
	{
		$fields = [];

		foreach ($this->ormRegistry->getCollections() as $collection) {
			if ($collection->isHidden()) {
				continue;
			}

			$typeName = $this->getTypeName($collection->getName());
			$type = $this->types[$typeName] ?? Type::string();

			$connectionType = $this->buildConnectionType($typeName, $type);

			$findAllResolver = $collection->metadata('gql::resolver::findAll');
			$fields[$collection->getName()] = [
				'type' => $connectionType,
				'args' => $this->buildQueryArgs($collection),
				'resolve' => $findAllResolver !== null
					? $this->wrapResolver($findAllResolver, false)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						if ($this->resolver === null) {
							throw new \RuntimeException('No resolver configured. Pass a GraphQLResolverInterface or use GraphQLRegistryGenerator::withDatabase().');
						}
						return $this->resolver->resolveCollection($collection, $args ?? []);
					},
			];

			$findByIdResolver = $collection->metadata('gql::resolver::findById');
			$fields[$collection->getName() . '_by_id'] = [
				'type' => $type,
				'args' => ['id' => Type::nonNull(Type::id())],
				'resolve' => $findByIdResolver !== null
					? $this->wrapResolver($findByIdResolver, false)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						if ($this->resolver === null) {
							throw new \RuntimeException('No resolver configured. Pass a GraphQLResolverInterface or use GraphQLRegistryGenerator::withDatabase().');
						}
						return $this->resolver->resolveById($collection, $args['id'] ?? '');
					},
			];
		}

		return $fields;
	}

	protected function buildInputType(Collection $collection, bool $forUpdate = false): InputObjectType
	{
		$suffix = $forUpdate ? 'UpdateInput' : 'Input';
		$typeName = $this->getTypeName($collection->getName()) . $suffix;

		if (isset($this->inputTypes[$typeName])) {
			return $this->inputTypes[$typeName];
		}

		$fields = [];
		foreach ($collection->fields as $fieldName => $field) {
			if ($field->isPrimaryKey()) {
				continue;
			}
			$fieldType = $this->mapOrmTypeToGraphQL($field);
			if ($forUpdate && $fieldType instanceof \GraphQL\Type\Definition\NonNull) {
				$fieldType = $fieldType->getWrappedType();
			}
			$fields[$fieldName] = [
				'type' => $fieldType,
			];
		}

		// Add nested relation fields for create input types (not update)
		if (!$forUpdate) {
			foreach ($collection->relations as $relationName => $relation) {
				$targetCollectionName = $relation->getCollection();
				$targetCollection = $this->ormRegistry->getCollection($targetCollectionName);

				if ($targetCollection === null) {
					continue;
				}

				// Build a nested input type for the target collection (without its own relations to avoid infinite recursion)
				$nestedInputType = $this->buildNestedInputType($targetCollection);

				if ($relation instanceof HasManyRelation) {
					$fields[$relationName] = ['type' => Type::listOf($nestedInputType)];
				} elseif ($relation instanceof HasOneRelation) {
					$fields[$relationName] = ['type' => $nestedInputType];
				}
			}
		}

		if (empty($fields)) {
			$fields['_empty'] = ['type' => Type::string()];
		}

		$inputType = new InputObjectType([
			'name' => $typeName,
			'fields' => $fields,
		]);

		$this->inputTypes[$typeName] = $inputType;

		return $inputType;
	}

	protected function buildMutationFields(): array
	{
		$fields = [];

		foreach ($this->ormRegistry->getCollections() as $collection) {
			if ($collection->isHidden()) {
				continue;
			}

			$typeName = $this->getTypeName($collection->getName());
			$type = $this->types[$typeName] ?? Type::string();
			$createInputType = $this->buildInputType($collection);
			$updateInputType = $this->buildInputType($collection, true);

			$createResolver = $collection->metadata('gql::resolver::create');
			$fields['create_' . $collection->getName()] = [
				'type' => $type,
				'args' => ['input' => Type::nonNull($createInputType)],
				'resolve' => $createResolver !== null
					? $this->wrapResolver($createResolver, false)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						if ($this->resolver === null) {
							throw new \RuntimeException('No resolver configured. Pass a GraphQLResolverInterface or use GraphQLRegistryGenerator::withDatabase().');
						}
						$input = $args['input'] ?? [];
						[$scalarInput, $nestedInput] = $this->separateNestedInput($collection, $input);

						if (empty($nestedInput)) {
							return $this->resolver->resolveCreate($collection, $scalarInput);
						}
						return $this->resolver->resolveNestedCreate($collection, $scalarInput, $nestedInput);
					},
			];

			$updateResolver = $collection->metadata('gql::resolver::update');
			$fields['update_' . $collection->getName()] = [
				'type' => $type,
				'args' => [
					'id' => Type::nonNull(Type::id()),
					'input' => Type::nonNull($updateInputType),
				],
				'resolve' => $updateResolver !== null
					? $this->wrapResolver($updateResolver, false)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						if ($this->resolver === null) {
							throw new \RuntimeException('No resolver configured. Pass a GraphQLResolverInterface or use GraphQLRegistryGenerator::withDatabase().');
						}
						return $this->resolver->resolveUpdate($collection, $args['id'] ?? '', $args['input'] ?? []);
					},
			];

			$deleteResolver = $collection->metadata('gql::resolver::delete');
			$fields['delete_' . $collection->getName()] = [
				'type' => Type::boolean(),
				'args' => ['id' => Type::nonNull(Type::id())],
				'resolve' => $deleteResolver !== null
					? $this->wrapResolver($deleteResolver, false)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						if ($this->resolver === null) {
							throw new \RuntimeException('No resolver configured. Pass a GraphQLResolverInterface or use GraphQLRegistryGenerator::withDatabase().');
						}
						return $this->resolver->resolveDelete($collection, $args['id'] ?? '');
					},
			];
		}

		return $fields;
	}

	protected function buildQueryArgs(Collection $collection): array
	{
		$args = [];
		foreach ($this->getFilterableFields($collection) as $name => $field) {
			$baseType = $this->mapOrmTypeToGraphQL($field);
			if (is_string($baseType)) {
				$baseType = Type::string();
			} elseif ($baseType instanceof \GraphQL\Type\Definition\NonNull) {
				$baseType = $baseType->getWrappedType();
			}
			$args[$name] = ['type' => $baseType];
		}

		$args['sort'] = ['type' => Type::string()];
		$args['order'] = ['type' => Type::string()];
		$args['limit'] = ['type' => Type::int()];
		$args['offset'] = ['type' => Type::int()];

		return $args;
	}

	protected function getFilterableFields(Collection $collection): array
	{
		$fields = [];
		foreach ($collection->fields as $name => $field) {
			if (!$field->isPrimaryKey() && $field->isFilterable()) {
				$fields[$name] = $field;
			}
		}
		return $fields;
	}

	protected function buildNestedInputType(Collection $collection): InputObjectType
	{
		$typeName = $this->getTypeName($collection->getName()) . 'NestedInput';

		if (isset($this->inputTypes[$typeName])) {
			return $this->inputTypes[$typeName];
		}

		$fields = [];
		foreach ($collection->fields as $fieldName => $field) {
			if ($field->isPrimaryKey()) {
				continue;
			}
			$fieldType = $this->mapOrmTypeToGraphQL($field);
			// All nested fields are nullable (the parent sets the FK)
			if ($fieldType instanceof \GraphQL\Type\Definition\NonNull) {
				$fieldType = $fieldType->getWrappedType();
			}
			$fields[$fieldName] = ['type' => $fieldType];
		}

		if (empty($fields)) {
			$fields['_empty'] = ['type' => Type::string()];
		}

		$inputType = new InputObjectType([
			'name' => $typeName,
			'fields' => $fields,
		]);

		$this->inputTypes[$typeName] = $inputType;

		return $inputType;
	}

	protected function separateNestedInput(Collection $collection, array $input): array
	{
		$scalarInput = [];
		$nestedInput = [];

		$relationNames = [];
		foreach ($collection->relations as $name => $relation) {
			$relationNames[$name] = true;
		}

		foreach ($input as $key => $value) {
			if (isset($relationNames[$key]) && (is_array($value))) {
				$nestedInput[$key] = $value;
			} else {
				$scalarInput[$key] = $value;
			}
		}

		return [$scalarInput, $nestedInput];
	}
}
