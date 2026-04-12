<?php

declare(strict_types=1);

namespace ON\GraphQL;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use function implode;
use ON\DB\DatabaseInterface;
use ON\GraphQL\DataLoader\GenericDataLoader;
use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Field\Field;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\HasManyRelation;
use ON\ORM\Definition\Relation\HasOneRelation;
use ON\ORM\Definition\Relation\RelationInterface;
use PDO;
use function preg_replace;
use Psr\Container\ContainerInterface;
use RuntimeException;
use function sprintf;
use function strtoupper;

class GraphQLRegistryGenerator
{
	protected Registry $registry;
	protected array $types = [];
	protected array $typeFieldsMap = [];
	protected GenericDataLoader $dataLoader;

	public function __construct(
		protected Registry $ormRegistry,
		protected ?ContainerInterface $container = null,
		protected ?DatabaseInterface $database = null
	) {
		if ($database !== null) {
			$this->dataLoader = new GenericDataLoader($ormRegistry);
		}
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
		$targetTypeObj = $this->types[$targetType] ?? null;

		if ($targetTypeObj === null) {
			$targetTypeObj = $this->createSyntheticType($relation->getCollection());
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
					return $this->resolveRelation($source, $relation);
				},
		];
	}

	protected function createSyntheticType(string $collectionName): ObjectType
	{
		$collection = $this->ormRegistry->getCollection($collectionName);
		if (! $collection) {
			return new ObjectType(['name' => $collectionName, 'fields' => []]);
		}

		$fields = [];
		foreach ($collection->fields as $fieldName => $field) {
			$fields[$fieldName] = $this->convertField($field);
		}

		foreach ($collection->relations as $relationName => $relation) {
			$fields[$relationName] = $this->convertRelation($relation);
		}

		return new ObjectType([
			'name' => $collectionName,
			'fields' => $fields,
		]);
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
		try {
			// When Database + DataLoader available, use SQL with batching
			if (isset($this->dataLoader) && $this->database !== null) {
				return $this->resolveRelationFromDatabase($source, $relation);
			}

			// Fallback: property access
			$property = $relation->getInnerKey();
			$value = $source->{$property} ?? null;

			if ($relation instanceof HasManyRelation && is_array($value)) {
				return $value;
			}

			return $value;
		} catch (\Throwable $e) {
			error_log('Error resolving relation: ' . $e->getMessage());
			throw $e;
		}
	}

	protected function resolveRelationFromDatabase($source, RelationInterface $relation): array
	{
		$collectionName = $relation->getCollection();
		$innerKey = $relation->getInnerKey();
		$outerKey = $relation->getOuterKey();

		// Get source's outer key value (e.g., user->id)
		$sourceId = is_array($source)
			? ($source[$outerKey] ?? null)
			: ($source->{$outerKey} ?? null);

		if ($sourceId === null) {
			return [];
		}

		// Get Collection for table lookup
		$collection = $this->ormRegistry->getCollection($collectionName);
		if ($collection === null) {
			throw new \RuntimeException("Collection not found: {$collectionName}");
		}

		// Check DataLoader cache first (use collection NAME string, not object)
		$cacheKey = $this->dataLoader->normalizeKey($collectionName, ['id' => $sourceId]);
		$cached = $this->dataLoader->get($collectionName, ['id' => $sourceId]);
		if ($cached !== null) {
			return $cached;
		}

		// Direct query for relations
		$table = $collection->getTable();

		$sql = "SELECT * FROM {$table} WHERE {$innerKey} = ?";
		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute([$sourceId]);

		$results = $stmt->fetchAll(PDO::FETCH_OBJ) ?: [];

		// Cache for potential reuse (use collection NAME string)
		$this->dataLoader->set($collectionName, ['id' => $sourceId], $results);

		return $results;
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
				'args' => $this->buildQueryArgs($collection),
				'resolve' => $findAllResolver !== null
					? $this->wrapResolver($findAllResolver)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						return $this->resolveCollection($collection, $args ?? []);
					},
			];

			$findByIdResolver = $collection->metadata('gql::resolver::findById');
			$fields[$collection->name . '_by_id'] = [
				'type' => $type,
				'args' => ['id' => Type::nonNull(Type::id())],
				'resolve' => $findByIdResolver !== null
					? $this->wrapResolver($findByIdResolver)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						return $this->resolveById($collection, $args['id'] ?? '');
					},
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

	protected function resolveCollection(Collection $collection, array $args = []): array
	{
		if ($this->database === null) {
			throw new RuntimeException(
				sprintf(
					'Database not configured. Pass DatabaseInterface to %s constructor or define ->metadata("gql::resolver::findAll", callable).',
					self::class
				)
			);
		}

		// For collections: direct query
		$table = $collection->getTable();
		$where = $this->buildWhereClause($collection, $args);
		$orderBy = $this->buildOrderBy($collection, $args);

		$sql = "SELECT * FROM {$table} {$where} {$orderBy}";

		$values = $this->extractFilterValues($collection, $args);

		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute($values);

		$results = $stmt->fetchAll(PDO::FETCH_OBJ) ?: [];

		// Pre-populate DataLoader cache for potential relation loads
		if (isset($this->dataLoader)) {
			foreach ($results as $item) {
				$id = is_array($item) ? ($item['id'] ?? null) : ($item->id ?? null);
				if ($id !== null) {
					$this->dataLoader->set($collection->name, ['id' => $id], $item);
				}
			}
		}

		return $results;
	}

	protected function resolveById(Collection $collection, string $id): ?object
	{
		if ($this->database === null) {
			throw new RuntimeException(
				sprintf(
					'Database not configured. Pass DatabaseInterface to %s constructor or define ->metadata("gql::resolver::findById", callable).',
					self::class
				)
			);
		}

		$table = $collection->getTable();
		$pkColumn = $this->getPrimaryKeyColumn($collection);

		$sql = "SELECT * FROM {$table} WHERE {$pkColumn} = ?";

		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute([$id]);

		return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
	}

	protected function resolveCreate(Collection $collection, array $input): ?object
	{
		if ($this->database === null) {
			throw new RuntimeException(
				sprintf(
					'Database not configured. Pass DatabaseInterface to %s constructor or define ->metadata("gql::resolver::create", callable).',
					self::class
				)
			);
		}

		$table = $collection->getTable();

		$columns = array_keys($input);
		$placeholders = array_fill(0, count($columns), '?');

		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s)',
			$table,
			implode(', ', $columns),
			implode(', ', $placeholders)
		);

		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute(array_values($input));

		$pkColumn = $this->getPrimaryKeyColumn($collection);
		$lastId = $this->database->getConnection()->lastInsertId();

		return $this->resolveById($collection, (string) $lastId);
	}

	protected function resolveUpdate(Collection $collection, string $id, array $input): ?object
	{
		if ($this->database === null) {
			throw new RuntimeException(
				sprintf(
					'Database not configured. Pass DatabaseInterface to %s constructor or define ->metadata("gql::resolver::update", callable).',
					self::class
				)
			);
		}

		$table = $collection->getTable();
		$pkColumn = $this->getPrimaryKeyColumn($collection);

		$sets = [];
		foreach (array_keys($input) as $column) {
			$sets[] = "{$column} = ?";
		}

		$sql = sprintf(
			'UPDATE %s SET %s WHERE %s = ?',
			$table,
			implode(', ', $sets),
			$pkColumn
		);

		$values = array_values($input);
		$values[] = $id;

		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute($values);

		return $this->resolveById($collection, $id);
	}

	protected function resolveDelete(Collection $collection, string $id): bool
	{
		if ($this->database === null) {
			throw new RuntimeException(
				sprintf(
					'Database not configured. Pass DatabaseInterface to %s constructor or define ->metadata("gql::resolver::delete", callable).',
					self::class
				)
			);
		}

		$table = $collection->getTable();
		$pkColumn = $this->getPrimaryKeyColumn($collection);

		$sql = "DELETE FROM {$table} WHERE {$pkColumn} = ?";

		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute([$id]);

		return $stmt->rowCount() > 0;
	}

	protected function buildQueryArgs(Collection $collection): array
	{
		$args = [];

		foreach ($collection->fields as $name => $field) {
			if (! $field->isPrimaryKey() && $field->isFilterable()) {
				$args[$name] = ['type' => Type::string()];
			}
		}

		$args['sort'] = ['type' => Type::string()];
		$args['order'] = ['type' => Type::string()];

		return $args;
	}

	protected function buildWhereClause(Collection $collection, array $args): string
	{
		if (empty($args)) {
			return '';
		}

		$conditions = [];
		$filterableFields = [];

		foreach ($collection->fields as $name => $field) {
			if (! $field->isPrimaryKey() && $field->isFilterable()) {
				$filterableFields[$name] = $field->getColumn();
			}
		}

		foreach ($args as $argName => $value) {
			if (isset($filterableFields[$argName])) {
				$conditions[] = "{$filterableFields[$argName]} = ?";
			}
		}

		if (empty($conditions)) {
			return '';
		}

		return 'WHERE ' . implode(' AND ', $conditions);
	}

	protected function buildOrderBy(Collection $collection, array $args): string
	{
		$sort = $args['sort'] ?? $args['orderBy'] ?? null;
		$order = $args['order'] ?? 'ASC';

		if ($sort === null) {
			return '';
		}

		$sort = preg_replace('/[^a-zA-Z0-9_]/', '', $sort);
		$order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

		return "ORDER BY {$sort} {$order}";
	}

	protected function extractFilterValues(Collection $collection, array $args): array
	{
		$filterableFields = [];

		foreach ($collection->fields as $name => $field) {
			if (! $field->isPrimaryKey() && $field->isFilterable()) {
				$filterableFields[$name] = $field->getColumn();
			}
		}

		$values = [];
		foreach ($args as $argName => $value) {
			if (isset($filterableFields[$argName])) {
				$values[] = $value;
			}
		}

		return $values;
	}

	protected function getPrimaryKeyColumn(Collection $collection): string
	{
		$pk = $collection->getPrimaryKey();

		if ($pk instanceof FieldInterface) {
			return $pk->getColumn();
		}

		if (\is_array($pk) && ! empty($pk)) {
			return $pk[0]->getColumn();
		}

		return 'id';
	}
}
