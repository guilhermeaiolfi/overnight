<?php

declare(strict_types=1);

namespace ON\GraphQL;

use Closure;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use LogicException;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Field\Field;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\RelationInterface;
use ON\GraphQL\Error\GraphQLUserError;
use ON\GraphQL\Event\AfterMutation;
use ON\GraphQL\Event\BeforeMutation;
use ON\GraphQL\Resolver\CycleResolver;
use ON\GraphQL\Resolver\GraphQLResolverInterface;
use ON\GraphQL\Resolver\NestedCreateRelations;
use ON\GraphQL\Resolver\SqlResolver;
use ON\GraphQL\Type\UploadType;
use ON\Validation\CollectionValidator;
use ON\Validation\ValidationFailedException;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionMethod;
use RuntimeException;

class GraphQLRegistryGenerator
{
	protected array $types = [];
	protected array $inputTypes = [];
	protected array $enumTypes = [];
	/** @var array<string, array<string, string>> */
	protected array $enumNames = [];
	protected static ?UploadType $uploadType = null;
	protected CollectionValidator $validator;
	protected ?Closure $authorizer = null;

	public function __construct(
		protected Registry $ormRegistry,
		protected ?GraphQLResolverInterface $resolver = null,
		protected ?EventDispatcherInterface $eventDispatcher = null,
		?CollectionValidator $validator = null,
		?callable $authorizer = null,
	) {
		$this->validator = $validator ?? new CollectionValidator();
		$this->authorizer = $authorizer !== null ? $authorizer(...) : null;
	}

	public function generate(): Schema
	{
		$this->types = [];
		$this->inputTypes = [];
		$this->enumTypes = [];
		$this->enumNames = $this->allocateEnumNames();

		$this->buildTypes();
		$queryFields = $this->buildQueryFields();
		$mutationFields = $this->buildMutationFields();

		$queryType = new ObjectType([
			'name' => 'Query',
			'description' => 'Root query type',
			'fields' => $queryFields,
		]);

		$mutationType = ! empty($mutationFields)
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
			'typeLoader' => fn (string $name) => $this->types[$name] ?? $this->inputTypes[$name] ?? $this->enumTypes[$name] ?? null,
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
				'description' => $collection->getDescription(),
				'fields' => fn () => $this->buildFieldsForCollection($collection),
			]);
		}
	}

	protected function buildFieldsForCollection(Collection $collection): array
	{
		$fields = [];

		foreach ($collection->fields as $fieldName => $field) {
			if ($field->isHidden()) {
				continue;
			}
			$fields[$fieldName] = $this->convertField($field);
		}

		foreach ($collection->relations as $relationName => $relation) {
			$targetCollection = $this->ormRegistry->getCollection($relation->getCollectionName());
			if ($targetCollection !== null && $targetCollection->isHidden()) {
				continue;
			}
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
			'description' => $field->getDescription() ?? $field->getColumn(),
			'resolve' => $resolver !== null
				? $this->wrapResolver($resolver)
				: fn ($source) => $source->{$field->getColumn()} ?? $source->{$field->getName()} ?? null,
		];
	}

	protected function convertRelation(RelationInterface $relation): array
	{
		$targetType = $this->getTypeName($relation->getCollectionName());
		$targetTypeObj = $this->types[$targetType] ?? null;

		if ($targetTypeObj === null) {
			$targetTypeObj = $this->buildFallbackType($relation->getCollectionName());
			$this->types[$targetType] = $targetTypeObj;
		}

		if ($relation->getCardinality()->isSingle()) {
			$type = $targetTypeObj;
		} elseif ($relation->getCardinality()->isMany()) {
			$type = Type::listOf($targetTypeObj);
		} else {
			$type = Type::string();
		}

		$resolver = $relation->metadata('gql::resolver');
		$customResolver = $resolver !== null ? $this->wrapResolver($resolver) : null;

		return [
			'type' => $type,
			'resolve' => function ($source, array $args = [], mixed $context = null) use ($relation, $customResolver) {
				$targetCollection = $this->ormRegistry->getCollection($relation->getCollectionName());
				if ($targetCollection instanceof Collection) {
					$this->authorize($targetCollection, 'relation', $args, $context);
				}

				if ($customResolver !== null) {
					return $customResolver($source, $args, $context);
				}
				if ($this->resolver !== null) {
					return $this->resolver->resolveRelation($source, $relation);
				}

				$property = $relation->getName();
				$value = $source->{$property} ?? null;

				return $relation->getCardinality()->isSingle() && is_array($value)
					? ($value[0] ?? null)
					: $value;
			},
		];
	}

	protected function buildFallbackType(string $collectionName): ObjectType
	{
		$collection = $this->ormRegistry->getCollection($collectionName);
		$typeName = $this->getTypeName($collectionName);

		if (! $collection || $collection->isHidden()) {
			return new ObjectType(['name' => $typeName, 'fields' => []]);
		}

		return new ObjectType([
			'name' => $typeName,
			'description' => $collection->getDescription(),
			'fields' => fn () => $this->buildFieldsForCollection($collection),
		]);
	}

	protected function wrapResolver(callable $resolver, bool $includeSource = true): callable
	{
		return function ($source, array $args = [], mixed $context = null) use ($resolver, $includeSource) {
			$params = $includeSource ? [$source, $args] : [$args];
			$params[] = $context;

			return $resolver(...$params);
		};
	}

	protected function mapOrmTypeToGraphQL(Field $field): Type|string
	{
		$type = $field->getType();
		$isNonNull = ! $field->isNullable();

		// Check for enum values via metadata
		$enumValues = $field->metadata('enum');
		if (is_array($enumValues)) {
			$graphqlType = $this->buildEnumType($field, $enumValues);
		} else {
			$graphqlType = match ($type) {
				'string', 'text', 'varchar', 'char' => Type::string(),
				'int', 'integer', 'smallint', 'bigint' => Type::int(),
				'bool', 'boolean' => Type::boolean(),
				'float', 'double', 'decimal' => Type::float(),
				'datetime', 'date', 'time', 'timestamp' => Type::string(),
				'id', 'uuid' => Type::id(),
				'file', 'image', 'upload' => self::$uploadType ??= new UploadType(),
				default => Type::string(),
			};
		}

		if ($isNonNull) {
			$graphqlType = Type::nonNull($graphqlType);
		}

		return $graphqlType;
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private function allocateEnumNames(): array
	{
		$reserved = array_fill_keys(['Query', 'Mutation', 'Upload', 'String', 'Int', 'Float', 'Boolean', 'ID'], true);
		$candidates = [];
		$counts = [];

		foreach ($this->ormRegistry->getCollections() as $collection) {
			if ($collection->isHidden()) {
				continue;
			}

			$name = $collection->getName();
			$type = $this->getTypeName($name);
			foreach (['', 'Connection', 'Input', 'UpdateInput', 'NestedInput'] as $suffix) {
				$reserved[$type . $suffix] = true;
			}

			foreach ($collection->relations as $relation) {
				$reserved[$this->getTypeName($relation->getCollectionName())] = true;
			}

			foreach ($collection->fields as $field) {
				if ($field->isHidden() || ! is_array($field->metadata('enum'))) {
					continue;
				}

				$fieldName = $field->getName();
				$short = ucfirst($fieldName) . 'Enum';
				$candidates[] = [$name, $fieldName, $short, $type . $short];
				$counts[$short] = ($counts[$short] ?? 0) + 1;
			}
		}

		usort($candidates, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);
		$names = [];
		$qualified = [];
		foreach ($candidates as [$collection, $field, $short, $long]) {
			if ($counts[$short] === 1 && ! isset($reserved[$short])) {
				$names[$collection][$field] = $short;
				$reserved[$short] = true;
			} else {
				$qualified[] = [$collection, $field, $long];
			}
		}

		foreach ($qualified as [$collection, $field, $preferred]) {
			$name = $preferred;
			for ($suffix = 2; isset($reserved[$name]); $suffix++) {
				$name = $preferred . $suffix;
			}
			$names[$collection][$field] = $name;
			$reserved[$name] = true;
		}

		return $names;
	}

	protected function buildEnumType(Field $field, array $values): EnumType
	{
		$fieldName = $field->getName();
		$collectionName = $field->getParent()->getName();
		$enumName = $this->enumNames[$collectionName][$fieldName];

		if (isset($this->enumTypes[$enumName])) {
			return $this->enumTypes[$enumName];
		}

		$enumValues = [];
		foreach ($values as $value) {
			$enumValues[strtoupper(str_replace([' ', '-'], '_', (string) $value))] = ['value' => $value];
		}

		$enumType = new EnumType([
			'name' => $enumName,
			'values' => $enumValues,
		]);

		$this->enumTypes[$enumName] = $enumType;

		return $enumType;
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
					? $this->wrapCollectionResolver($collection, 'findAll', $findAllResolver)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						$this->authorize($collection, 'findAll', $args ?? [], $context);

						if ($this->resolver === null) {
							throw new RuntimeException('No resolver configured. Pass a GraphQLResolverInterface to GraphQLRegistryGenerator.');
						}

						return $this->resolver->resolveCollection($collection, $args ?? []);
					},
			];

			$findByIdResolver = $collection->metadata('gql::resolver::findById');
			$fields[$collection->getName() . '_by_id'] = [
				'type' => $type,
				'args' => ['id' => Type::nonNull(Type::id())],
				'resolve' => $findByIdResolver !== null
					? $this->wrapCollectionResolver($collection, 'findById', $findByIdResolver)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						$this->authorize($collection, 'findById', $args ?? [], $context);

						if ($this->resolver === null) {
							throw new RuntimeException('No resolver configured. Pass a GraphQLResolverInterface to GraphQLRegistryGenerator.');
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
			if ($field->isHidden()) {
				continue;
			}
			$fieldType = $this->mapOrmTypeToGraphQL($field);
			if ($forUpdate && $fieldType instanceof NonNull) {
				$fieldType = $fieldType->getWrappedType();
			}
			$fields[$fieldName] = [
				'type' => $fieldType,
			];
		}

		// Add nested relation fields for create input types (not update)
		if (! $forUpdate) {
			$usesBuiltInNestedCreate = $this->usesBuiltInNestedCreate($collection);
			foreach ($collection->relations as $relationName => $relation) {
				if ($usesBuiltInNestedCreate && ! NestedCreateRelations::supports($relation)) {
					continue;
				}

				$targetCollection = $this->ormRegistry->getCollection($relation->getCollectionName());

				if (! $targetCollection instanceof Collection || $targetCollection->isHidden()) {
					continue;
				}

				// Build a nested input type for the target collection (without its own relations to avoid infinite recursion)
				$nestedInputType = $this->buildNestedInputType($targetCollection);

				if ($relation->getCardinality()->isMany()) {
					$fields[$relationName] = ['type' => Type::listOf($nestedInputType)];
				} else {
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

	protected function usesBuiltInNestedCreate(Collection $collection): bool
	{
		return $collection->metadata('gql::resolver::create') === null
			&& ($this->resolver === null || $this->builtInNestedCreateResolver() !== null);
	}

	private function builtInNestedCreateResolver(): SqlResolver|CycleResolver|null
	{
		if (! $this->resolver instanceof SqlResolver && ! $this->resolver instanceof CycleResolver) {
			return null;
		}

		$declaringClass = (new ReflectionMethod($this->resolver, 'resolveNestedCreate'))->getDeclaringClass()->getName();

		return in_array($declaringClass, [SqlResolver::class, CycleResolver::class], true) ? $this->resolver : null;
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
					? $this->wrapCollectionResolver($collection, 'create', $createResolver)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						$this->authorize($collection, 'create', $args ?? [], $context);

						if ($this->resolver === null) {
							throw new RuntimeException('No resolver configured.');
						}
						$input = $args['input'] ?? [];
						[$scalarInput, $nestedInput] = $this->separateNestedInput($collection, $input);

						$this->validateInput($collection, $scalarInput);
						$builtInResolver = $this->builtInNestedCreateResolver();
						if ($builtInResolver === null) {
							$this->validateNestedInput($collection, $nestedInput, $context);
						}

						// Dispatch before event — listeners can modify input (e.g., process file uploads)
						if ($this->eventDispatcher !== null) {
							$event = new BeforeMutation($collection, 'create', $scalarInput);
							$this->eventDispatcher->dispatch($event);
							$scalarInput = $event->getInput();
						}

						if ($nestedInput === []) {
							$result = $this->resolver->resolveCreate($collection, $scalarInput);
						} elseif ($builtInResolver !== null) {
							$result = $builtInResolver->resolveNestedCreateWithChildPreparation(
								$collection,
								$scalarInput,
								$nestedInput,
								fn (string $relationName, int|string $index, RelationInterface $relation, array $effectiveInput)
									=> $this->validateEffectiveChild($relationName, $index, $relation, $effectiveInput, $context),
							);
						} else {
							$result = $this->resolver->resolveNestedCreate($collection, $scalarInput, $nestedInput);
						}

						// Dispatch after event
						if ($this->eventDispatcher !== null) {
							$this->eventDispatcher->dispatch(new AfterMutation($collection, 'create', $result));
						}

						return $result;
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
					? $this->wrapCollectionResolver($collection, 'update', $updateResolver)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						$this->authorize($collection, 'update', $args ?? [], $context);

						if ($this->resolver === null) {
							throw new RuntimeException('No resolver configured.');
						}
						$input = $args['input'] ?? [];
						$this->validateInput($collection, $input, partial: true);

						if ($this->eventDispatcher !== null) {
							$event = new BeforeMutation($collection, 'update', $input);
							$this->eventDispatcher->dispatch($event);
							$input = $event->getInput();
						}

						$result = $this->resolver->resolveUpdate($collection, $args['id'] ?? '', $input);

						if ($this->eventDispatcher !== null) {
							$this->eventDispatcher->dispatch(new AfterMutation($collection, 'update', $result));
						}

						return $result;
					},
			];

			$deleteResolver = $collection->metadata('gql::resolver::delete');
			$fields['delete_' . $collection->getName()] = [
				'type' => $type,
				'args' => ['id' => Type::nonNull(Type::id())],
				'resolve' => $deleteResolver !== null
					? $this->wrapCollectionResolver($collection, 'delete', $deleteResolver)
					: function ($root, $args, $context = null, $info = null) use ($collection) {
						$this->authorize($collection, 'delete', $args ?? [], $context);

						if ($this->resolver === null) {
							throw new RuntimeException('No resolver configured.');
						}

						if ($this->eventDispatcher !== null) {
							$event = new BeforeMutation($collection, 'delete', ['id' => $args['id'] ?? '']);
							$this->eventDispatcher->dispatch($event);
						}

						$result = $this->resolver->resolveDelete($collection, $args['id'] ?? '');

						if ($this->eventDispatcher !== null) {
							$this->eventDispatcher->dispatch(new AfterMutation($collection, 'delete', $result));
						}

						return $result;
					},
			];
		}

		return $fields;
	}

	protected function buildQueryArgs(Collection $collection): array
	{
		$args = [];
		foreach ($this->getFilterableFields($collection) as $name => $field) {
			if ($field->isHidden()) {
				continue;
			}
			$baseType = $this->mapOrmTypeToGraphQL($field);
			if (is_string($baseType)) {
				$baseType = Type::string();
			} elseif ($baseType instanceof NonNull) {
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
			if (! $field->isPrimaryKey() && $field->isFilterable()) {
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
			if ($field->isHidden()) {
				continue;
			}
			$fieldType = $this->mapOrmTypeToGraphQL($field);
			// All nested fields are nullable (the parent sets the FK)
			if ($fieldType instanceof NonNull) {
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
			$targetCollection = $this->ormRegistry->getCollection($relation->getCollectionName());
			if ($targetCollection !== null && $targetCollection->isHidden()) {
				continue;
			}
			$relationNames[$name] = true;
		}

		foreach ($input as $key => $value) {
			if (isset($relationNames[$key]) && is_array($value)) {
				$nestedInput[$key] = $value;
			} else {
				$scalarInput[$key] = $value;
			}
		}

		return [$scalarInput, $nestedInput];
	}

	/**
	 * Validate input against field validation rules defined on the collection.
	 * Throws GraphQLUserError if validation fails.
	 */
	protected function validateInput(Collection $collection, array $input, bool $partial = false): void
	{
		try {
			$this->validator->validate($collection, $input, partial: $partial, skipPrimaryKey: true);
		} catch (ValidationFailedException $e) {
			throw GraphQLUserError::validationFailed($e->getErrors());
		}
	}

	/**
	 * Validate nested child inputs against each target collection's validation rules.
	 */
	protected function validateNestedInput(
		Collection $collection,
		array $nestedInput,
		mixed $context,
		bool $validate = true,
	): void {
		$allErrors = [];

		foreach ($nestedInput as $relationName => $relationData) {
			if (! $collection->relations->has($relationName)) {
				continue;
			}

			$relation = $collection->relations->get($relationName);
			$targetCollection = $this->ormRegistry->getCollection($relation->getCollectionName());

			if (! $targetCollection instanceof Collection || $targetCollection->isHidden()) {
				continue;
			}

			$isMany = $relation->getCardinality()->isMany();
			$children = $isMany ? $relationData : [$relationData];
			foreach ($children as $index => $childInput) {
				if (! is_array($childInput)) {
					continue;
				}
				$this->authorize($targetCollection, 'create', ['input' => $childInput], $context);

				if ($validate) {
					try {
						$outerKeys = $relation->getOuterKeys();
					} catch (LogicException) {
						$outerKeys = [];
					}
					$this->collectNestedValidationErrors(
						$targetCollection,
						$childInput,
						$outerKeys,
						$isMany ? "{$relationName}.{$index}" : $relationName,
						$allErrors,
					);
				}
			}
		}

		if ($allErrors !== []) {
			throw GraphQLUserError::validationFailed($allErrors);
		}
	}

	private function validateEffectiveChild(
		string $relationName,
		int|string $index,
		RelationInterface $relation,
		array $input,
		mixed $context,
	): void {
		$target = $this->ormRegistry->getCollection($relation->getCollectionName());
		if (! $target instanceof Collection || $target->isHidden()) {
			throw new GraphQLUserError('Nested create is not supported for this relation.', 'INVALID_INPUT', $relationName);
		}

		$this->authorize($target, 'create', ['input' => $input], $context);

		$errors = [];
		$prefix = $relation->getCardinality()->isMany() ? "{$relationName}.{$index}" : $relationName;
		$this->collectNestedValidationErrors($target, $input, [], $prefix, $errors);
		if ($errors !== []) {
			throw GraphQLUserError::validationFailed($errors);
		}
	}

	/**
	 * @param array<string, array<int, string>> $allErrors
	 */
	protected function collectNestedValidationErrors(
		Collection $targetCollection,
		array $childInput,
		array $outerFieldNames,
		string $prefix,
		array &$allErrors,
	): void {
		try {
			$this->validator->validate($targetCollection, $childInput, partial: false, skipPrimaryKey: true);
		} catch (ValidationFailedException $e) {
			foreach ($e->getErrors() as $field => $messages) {
				// The parent sets the relation's outerKey FK after creating the parent row
				if (in_array($field, $outerFieldNames, true) && ! array_key_exists($field, $childInput)) {
					continue;
				}
				$allErrors["{$prefix}.{$field}"] = $messages;
			}
		}
	}

	protected function wrapCollectionResolver(Collection $collection, string $operation, callable $resolver): callable
	{
		return function ($source, array $args = [], mixed $context = null) use ($collection, $operation, $resolver) {
			$this->authorize($collection, $operation, $args, $context);
			if ($operation === 'create' && is_array($args['input'] ?? null)) {
				[, $nestedInput] = $this->separateNestedInput($collection, $args['input']);
				// The application resolver owns checks against the final values it persists.
				$this->validateNestedInput($collection, $nestedInput, $context, validate: false);
			}

			return $resolver($args, $context);
		};
	}

	protected function authorize(Collection $collection, string $operation, array $args, mixed $context): void
	{
		if ($this->authorizer !== null && ($this->authorizer)($collection, $operation, $args, $context) !== true) {
			throw new GraphQLUserError('Access denied.', 'FORBIDDEN');
		}

		$collectionAuthorizer = $collection->metadata('gql::authorize');
		if ($collectionAuthorizer !== null && (! is_callable($collectionAuthorizer) || $collectionAuthorizer($collection, $operation, $args, $context) !== true)) {
			throw new GraphQLUserError('Access denied.', 'FORBIDDEN');
		}
	}
}
