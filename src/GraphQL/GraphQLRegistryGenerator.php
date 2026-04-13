<?php

declare(strict_types=1);

namespace ON\GraphQL;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
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
use Psr\Container\ContainerInterface;
use RuntimeException;

class GraphQLRegistryGenerator
{
	protected array $types = [];
	protected array $inputTypes = [];
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

	protected function quoteIdentifier(string $identifier): string
	{
		$sanitized = preg_replace('/[^a-zA-Z0-9_.]/', '', $identifier);
		return "`{$sanitized}`";
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
					return $this->resolveRelation($source, $relation);
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

	protected function requireDatabase(string $operation): void
	{
		if ($this->database === null) {
			throw new RuntimeException(
				sprintf(
					'Database not configured. Pass DatabaseInterface to %s constructor or define ->metadata("%s", callable).',
					self::class,
					$operation
				)
			);
		}
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

			if ($relation instanceof HasOneRelation) {
				return is_array($value) ? ($value[0] ?? null) : $value;
			}

			if ($relation instanceof HasManyRelation && is_array($value)) {
				return $value;
			}

			return $value;
		} catch (\Throwable $e) {
			error_log('Error resolving relation: ' . $e->getMessage());
			throw $e;
		}
	}

	protected function resolveRelationFromDatabase($source, RelationInterface $relation): mixed
	{
		$collectionName = $relation->getCollection();
		$innerKey = $relation->getInnerKey();
		$outerKey = $relation->getOuterKey();

		$sourceId = is_array($source)
			? ($source[$outerKey] ?? null)
			: ($source->{$outerKey} ?? null);

		if ($sourceId === null) {
			return $relation instanceof HasOneRelation ? null : [];
		}

		$collection = $this->ormRegistry->getCollection($collectionName);
		if ($collection === null) {
			throw new \RuntimeException("Collection not found: {$collectionName}");
		}

		$table = $collection->getTable();
		$isHasOne = $relation instanceof HasOneRelation;

		$entityKey = $collectionName . ':' . $innerKey;

		return $this->dataLoader->load($entityKey, $sourceId, function (array $batchKeys) use ($table, $innerKey, $isHasOne) {
			$placeholders = implode(', ', array_fill(0, count($batchKeys), '?'));
			$flatKeys = [];
			foreach ($batchKeys as $keySet) {
				$flatKeys[] = is_array($keySet) ? reset($keySet) : $keySet;
			}

			$quotedTable = $this->quoteIdentifier($table);
			$quotedInnerKey = $this->quoteIdentifier($innerKey);
			$sql = "SELECT * FROM {$quotedTable} WHERE {$quotedInnerKey} IN ({$placeholders})";
			$stmt = $this->database->getConnection()->prepare($sql);
			$stmt->execute($flatKeys);

			$allResults = $stmt->fetchAll(PDO::FETCH_OBJ) ?: [];

			$grouped = [];
			foreach ($allResults as $row) {
				$key = $row->{$innerKey} ?? null;
				if ($key !== null) {
					$grouped[$key][] = $row;
				}
			}

			$results = [];
			foreach ($flatKeys as $i => $keyValue) {
				$rows = $grouped[$keyValue] ?? [];
				if ($isHasOne) {
					$results[$i] = $rows[0] ?? null;
				} else {
					$results[$i] = $rows;
				}
			}

			return $results;
		});
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
						return $this->resolveCollection($collection, $args ?? []);
					},
			];

			$findByIdResolver = $collection->metadata('gql::resolver::findById');
			$fields[$collection->getName() . '_by_id'] = [
				'type' => $type,
				'args' => ['id' => Type::nonNull(Type::id())],
				'resolve' => $findByIdResolver !== null
					? $this->wrapResolver($findByIdResolver, false)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						return $this->resolveById($collection, $args['id'] ?? '');
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
						return $this->resolveCreate($collection, $args['input'] ?? []);
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
						return $this->resolveUpdate($collection, $args['id'] ?? '', $args['input'] ?? []);
					},
			];

			$deleteResolver = $collection->metadata('gql::resolver::delete');
			$fields['delete_' . $collection->getName()] = [
				'type' => Type::boolean(),
				'args' => ['id' => Type::nonNull(Type::id())],
				'resolve' => $deleteResolver !== null
					? $this->wrapResolver($deleteResolver, false)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						return $this->resolveDelete($collection, $args['id'] ?? '');
					},
			];
		}

		return $fields;
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

	protected function resolveCollection(Collection $collection, array $args = []): array
	{
		$this->requireDatabase('gql::resolver::findAll');

		$table = $collection->getTable();
		$where = $this->buildWhereClause($collection, $args);
		$orderBy = $this->buildOrderBy($collection, $args);

		$limit = isset($args['limit']) ? ' LIMIT ' . (int) $args['limit'] : '';
		$offset = isset($args['offset']) ? ' OFFSET ' . (int) $args['offset'] : '';

		$quotedTable = $this->quoteIdentifier($table);

		$countSql = "SELECT COUNT(*) as cnt FROM {$quotedTable} {$where}";
		$values = $this->extractFilterValues($collection, $args);

		$countStmt = $this->database->getConnection()->prepare($countSql);
		$countStmt->execute($values);
		$countRow = $countStmt->fetch(PDO::FETCH_OBJ);
		$totalCount = $countRow ? (int) $countRow->cnt : 0;

		$sql = "SELECT * FROM {$quotedTable} {$where} {$orderBy}{$limit}{$offset}";
		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute($values);
		$items = $stmt->fetchAll(PDO::FETCH_OBJ) ?: [];

		return [
			'items' => $items,
			'totalCount' => $totalCount,
		];
	}

	protected function resolveById(Collection $collection, string $id): ?object
	{
		$this->requireDatabase('gql::resolver::findById');

		$table = $collection->getTable();
		$pkColumn = $this->getPrimaryKeyColumn($collection);

		$quotedTable = $this->quoteIdentifier($table);
		$quotedPk = $this->quoteIdentifier($pkColumn);
		$sql = "SELECT * FROM {$quotedTable} WHERE {$quotedPk} = ?";

		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute([$id]);

		return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
	}

	protected function resolveCreate(Collection $collection, array $input): ?object
	{
		$this->requireDatabase('gql::resolver::create');

		$table = $collection->getTable();

		$columns = array_keys($input);
		$quotedColumns = array_map(fn(string $col) => $this->quoteIdentifier($col), $columns);
		$placeholders = array_fill(0, count($columns), '?');

		$quotedTable = $this->quoteIdentifier($table);
		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s)',
			$quotedTable,
			implode(', ', $quotedColumns),
			implode(', ', $placeholders)
		);

		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute(array_values($input));

		$lastId = $this->database->getConnection()->lastInsertId();

		return $this->resolveById($collection, (string) $lastId);
	}

	protected function resolveUpdate(Collection $collection, string $id, array $input): ?object
	{
		$this->requireDatabase('gql::resolver::update');

		$table = $collection->getTable();
		$pkColumn = $this->getPrimaryKeyColumn($collection);

		$sets = [];
		foreach (array_keys($input) as $column) {
			$sets[] = $this->quoteIdentifier($column) . " = ?";
		}

		$quotedTable = $this->quoteIdentifier($table);
		$quotedPk = $this->quoteIdentifier($pkColumn);
		$sql = sprintf(
			'UPDATE %s SET %s WHERE %s = ?',
			$quotedTable,
			implode(', ', $sets),
			$quotedPk
		);

		$values = array_values($input);
		$values[] = $id;

		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute($values);

		return $this->resolveById($collection, $id);
	}

	protected function resolveDelete(Collection $collection, string $id): bool
	{
		$this->requireDatabase('gql::resolver::delete');

		$table = $collection->getTable();
		$pkColumn = $this->getPrimaryKeyColumn($collection);

		$quotedTable = $this->quoteIdentifier($table);
		$quotedPk = $this->quoteIdentifier($pkColumn);
		$sql = "DELETE FROM {$quotedTable} WHERE {$quotedPk} = ?";

		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute([$id]);

		return $stmt->rowCount() > 0;
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

	protected function buildWhereClause(Collection $collection, array $args): string
	{
		if (empty($args)) {
			return '';
		}

		$conditions = [];
		$filterableFields = $this->getFilterableFields($collection);

		foreach ($args as $argName => $value) {
			if (isset($filterableFields[$argName])) {
				$conditions[] = $this->quoteIdentifier($filterableFields[$argName]->getColumn()) . " = ?";
			}
		}

		return empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
	}

	protected function buildOrderBy(Collection $collection, array $args): string
	{
		$sort = $args['sort'] ?? null;
		$order = $args['order'] ?? 'ASC';

		if ($sort === null) {
			return '';
		}

		$validFields = [];
		foreach ($collection->fields as $name => $field) {
			$validFields[$name] = $field->getColumn();
		}

		if (!isset($validFields[$sort])) {
			return '';
		}

		$column = $validFields[$sort];
		$order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

		return "ORDER BY " . $this->quoteIdentifier($column) . " {$order}";
	}

	protected function extractFilterValues(Collection $collection, array $args): array
	{
		$filterableFields = $this->getFilterableFields($collection);
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
