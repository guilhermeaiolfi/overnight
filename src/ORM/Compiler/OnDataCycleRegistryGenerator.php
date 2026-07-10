<?php

declare(strict_types=1);

namespace ON\ORM\Compiler;

use Cycle\ORM\Schema\GeneratedField as CycleGeneratedField;
use Cycle\Schema\Definition\Entity;
use Cycle\Schema\Definition\Field as CycleField;
use Cycle\Schema\Definition\Relation as CycleRelation;
use Cycle\Schema\GeneratorInterface;
use Cycle\Schema\Registry as CycleRegistry;
use Cycle\Schema\Table\Column;
use DateTimeInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Exception\FieldException;
use ON\Data\Definition\Field\Field;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\ORM\Compiler\Exception\OnDataCycleSchemaException;
use ON\ORM\Compiler\Exception\UnsupportedOnDataFeatureException;

/**
 * Compiles an ON\Data definition registry into a Cycle schema registry.
 *
 * Side-by-side with {@see CycleRegistryGenerator}; not wired into production schema
 * compilation until parity is demonstrated.
 */
final class OnDataCycleRegistryGenerator implements GeneratorInterface
{
	/** @var list<string> */
	private const KNOWN_CYCLE_FIELD_TYPES = [
		'primary',
		'bigprimary',
		'serial',
		'bigserial',
		'smallserial',
		'bool',
		'boolean',
		'int',
		'integer',
		'tinyinteger',
		'smallinteger',
		'biginteger',
		'string',
		'text',
		'tinytext',
		'mediumtext',
		'longtext',
		'double',
		'float',
		'decimal',
		'datetime',
		'date',
		'time',
		'timestamp',
		'binary',
		'tinybinary',
		'longbinary',
		'json',
	];

	private ConversionGateway $gateway;

	public function __construct(
		private readonly Registry $registry,
		?ConversionGateway $gateway = null,
	) {
		$this->gateway = $gateway ?? ConversionGateway::createDefault();
	}

	public function run(CycleRegistry $cycle_registry): CycleRegistry
	{
		foreach ($this->registry->getCollections() as $collection) {
			$entity = $this->convertCollectionToEntity($collection);
			$cycle_registry->register($entity);
			$cycle_registry->linkTable($entity, $collection->getDatabase(), $collection->getTable());
		}

		return $cycle_registry;
	}

	private function convertCollectionToEntity(CollectionInterface $collection): Entity
	{
		$entity = new Entity();
		$this->convertEntityBaseAttributes($collection, $entity);
		$this->convertFieldMap($collection, $entity);
		$this->convertRelationMap($collection, $entity);

		return $entity;
	}

	private function convertEntityBaseAttributes(CollectionInterface $collection, Entity $entity): void
	{
		$entity->setRole($collection->getName());
		$entity->setClass($collection->getEntity());
		$entity->setMapper($collection->getMapper());
		$entity->setScope($collection->getScope());
		$entity->setRepository($collection->getRepository());
		$entity->setSource($collection->getSource());
		$entity->setDatabase($collection->getDatabase());
		$entity->setTableName($collection->getTable());
	}

	private function convertFieldMap(CollectionInterface $collection, Entity $entity): void
	{
		$cycleFields = $entity->getFields();

		foreach ($collection->getFields() as $name => $field) {
			$cycleField = $this->convertField($field);
			$cycleField->setEntityClass($entity->getClass());
			$cycleFields->set($name, $cycleField);
		}
	}

	private function convertField(FieldInterface $field): CycleField
	{
		$cycleField = new CycleField();
		$cycleField->setType($this->resolveCycleFieldType($field));
		$cycleField->setColumn($field->getColumn());
		$cycleField->setPrimary($field->isPrimaryKey());

		if ($this->isOnInsertGeneratedField($cycleField, $field)) {
			$cycleField->setGenerated(CycleGeneratedField::ON_INSERT);
		}

		$cycleField->setTypecast($field->getTypecast());

		if ($field->isNullable()) {
			$cycleField->getOptions()->set(Column::OPT_NULLABLE, true);
			$cycleField->getOptions()->set(Column::OPT_DEFAULT, null);
		}

		if ($field instanceof Field) {
			if ($field->hasDefault()) {
				$cycleField->getOptions()->set(Column::OPT_DEFAULT, $field->getDefault());
			}

			if ($field->castDefault()) {
				$cycleField->getOptions()->set(Column::OPT_CAST_DEFAULT, true);
			}
		}

		return $cycleField;
	}

	private function convertRelationMap(CollectionInterface $collection, Entity $entity): void
	{
		$cycleRelations = $entity->getRelations();

		foreach ($collection->getRelations() as $name => $relation) {
			$cycleRelations->set($name, $this->convertRelation($relation));
		}
	}

	private function convertRelation(RelationInterface $relation): CycleRelation
	{
		$cycleRelation = new CycleRelation();
		$cycleRelation
			->setTarget($relation->getCollectionName())
			->setType($this->resolveRelationType($relation));

		$this->convertOptionMap($relation, $cycleRelation);

		return $cycleRelation;
	}

	private function convertOptionMap(RelationInterface $relation, CycleRelation $cycleRelation): void
	{
		$innerKeys = $relation->getInnerKeys();
		$outerKeys = $relation->getOuterKeys();
		$this->assertMatchingKeyCardinality($relation, $innerKeys, $outerKeys);

		$parent = $this->requireCollection($relation->getParent(), $relation->getName(), 'parent');
		$target = $relation->getCollection();

		$options = $cycleRelation->getOptions()
			->set('load', $relation->getLoadStrategy())
			->set('cascade', $relation->isCascade())
			->set('nullable', $relation->isNullable())
			->set('innerKey', $this->relationColumns($parent, $innerKeys, $relation->getName(), 'inner'))
			->set('outerKey', $this->relationColumns($target, $outerKeys, $relation->getName(), 'outer'));

		if ($relation instanceof M2MRelation) {
			$through = $relation->getThrough();
			$throughInnerKeys = $through->getInnerKeys();
			$throughOuterKeys = $through->getOuterKeys();
			$this->assertMatchingKeyCardinality($relation, $throughInnerKeys, $throughOuterKeys, 'through');

			$throughCollection = $through->getCollection();
			$options
				->set('through', $throughCollection->getName())
				->set(
					'throughInnerKey',
					$this->relationColumns($throughCollection, $throughInnerKeys, $relation->getName(), 'through inner')
				)
				->set(
					'throughOuterKey',
					$this->relationColumns($throughCollection, $throughOuterKeys, $relation->getName(), 'through outer')
				);
		}
	}

	/**
	 * @param list<string> $keys
	 */
	private function relationColumns(
		DefinitionInterface $definition,
		array $keys,
		string $relationName,
		string $side,
	): string|array {
		$fields = $definition->getFields();
		$columns = [];

		foreach ($keys as $key) {
			if (! $fields->has($key)) {
				throw new OnDataCycleSchemaException(sprintf(
					'Relation "%s" %s key "%s" is not a field on collection "%s".',
					$relationName,
					$side,
					$key,
					$definition->getName(),
				));
			}

			$columns[] = $fields->get($key)->getColumn();
		}

		return count($columns) === 1 ? $columns[0] : $columns;
	}

	/**
	 * @param list<string> $left
	 * @param list<string> $right
	 */
	private function assertMatchingKeyCardinality(
		RelationInterface $relation,
		array $left,
		array $right,
		string $context = 'relation',
	): void {
		if (count($left) !== count($right)) {
			throw new OnDataCycleSchemaException(sprintf(
				'Relation "%s" %s key counts do not match (%d vs %d).',
				$relation->getName(),
				$context,
				count($left),
				count($right),
			));
		}
	}

	private function requireCollection(
		DefinitionInterface $definition,
		string $relationName,
		string $side,
	): CollectionInterface {
		if (! $definition instanceof CollectionInterface) {
			throw new OnDataCycleSchemaException(sprintf(
				'Relation "%s" %s must be a collection, got "%s".',
				$relationName,
				$side,
				$definition::class,
			));
		}

		return $definition;
	}

	private function resolveRelationType(RelationInterface $relation): string
	{
		if ($relation instanceof FirstOfManyRelation) {
			throw new UnsupportedOnDataFeatureException(sprintf(
				'FirstOfManyRelation "%s" is not supported by OnDataCycleRegistryGenerator. '
				. 'Cycle has no first-of-many relation type; do not approximate it as hasMany. '
				. 'See docs/ondata-cycle-schema-migration.md.',
				$relation->getName(),
			));
		}

		if ($relation instanceof M2MRelation) {
			return 'manyToMany';
		}

		if ($relation instanceof HasOneRelation) {
			if ($relation->isExclusive()) {
				return 'hasOne';
			}

			return $relation->isNullable() ? 'belongsTo' : 'refersTo';
		}

		if ($relation instanceof HasManyRelation) {
			return 'hasMany';
		}

		throw new UnsupportedOnDataFeatureException(sprintf(
			'Unsupported ON\Data relation class "%s" for relation "%s".',
			$relation::class,
			$relation->getName(),
		));
	}

	private function isOnInsertGeneratedField(CycleField $cycleField, FieldInterface $field): bool
	{
		if ($field->isAutoIncrement()) {
			return true;
		}

		return match ($cycleField->getType()) {
			'serial', 'bigserial', 'smallserial' => true,
			default => $cycleField->isPrimary(),
		};
	}

	private function resolveCycleFieldType(FieldInterface $field): string
	{
		$type = $field->getType();

		if ($type === '') {
			throw new FieldException(sprintf(
				'Field(%s) has an empty type in collection: %s',
				$field->getName(),
				$field->getParent()->getName(),
			));
		}

		if (class_exists($type) && is_subclass_of($type, FieldTypeInterface::class)) {
			$type = $type::getStorageType();
		}

		if (
			class_exists($type)
			&& ! is_subclass_of($type, FieldTypeInterface::class)
			&& ! is_subclass_of($type, DateTimeInterface::class)
			&& ! enum_exists($type)
		) {
			$this->assertKnownCycleFieldType($type, $field);
		}

		if (class_exists($type) && is_subclass_of($type, DateTimeInterface::class)) {
			$type = 'datetime';
		} else {
			$handler = $this->gateway->getMapperManager()->resolveFieldType(
				LeafNodeResolution::fromField($field)
			);

			if ($handler !== null && ! $this->isKnownCycleFieldType($type)) {
				$type = $handler::getStorageType();
			}
		}

		if (str_contains($type, '(')) {
			$this->assertKnownCycleFieldType($this->baseCycleFieldType($type), $field);

			return $type;
		}

		$this->assertKnownCycleFieldType($type, $field);

		if (strtolower($type) === 'string') {
			return sprintf('string(%d)', $this->fieldMaxLength($field));
		}

		return $type;
	}

	private function fieldMaxLength(FieldInterface $field): int
	{
		if ($field instanceof Field) {
			return $field->getMaxLength();
		}

		return 255;
	}

	private function baseCycleFieldType(string $type): string
	{
		return explode('(', $type, 2)[0];
	}

	private function assertKnownCycleFieldType(string $type, FieldInterface $field): void
	{
		if (! $this->isKnownCycleFieldType($type)) {
			throw new FieldException(sprintf(
				'Field(%s) type "%s" is not a known Cycle column type in collection: %s',
				$field->getName(),
				$field->getType(),
				$field->getParent()->getName(),
			));
		}
	}

	private function isKnownCycleFieldType(string $type): bool
	{
		return in_array(strtolower($type), self::KNOWN_CYCLE_FIELD_TYPES, true);
	}
}
