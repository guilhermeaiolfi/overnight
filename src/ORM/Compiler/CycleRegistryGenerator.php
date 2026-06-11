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
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Field\FieldTypeRegistry;
use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Exception\FieldException;
use ON\ORM\Definition\Field\Field;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\ORM\Definition\Relation\HasOneRelation;
use ON\ORM\Definition\Relation\RelationInterface;
use ReflectionClass;

/**
 * It gets a ON Registry and generates an Cycle Registry.
 */
class CycleRegistryGenerator implements GeneratorInterface
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

	public function __construct(
		protected Registry $on_registry,
	) {

	}

	public function run(CycleRegistry $cycle_registry): CycleRegistry
	{
		foreach ($this->on_registry->getCollections() as $collection) {
			$entity = $this->convertCollectionToEntity($collection);
			$cycle_registry->register($entity);
			$cycle_registry->linkTable($entity, $collection->getDatabase(), $collection->getTable());
		}

		return $cycle_registry;
	}

	protected function convertCollectionToEntity(Collection $collection): Entity
	{
		$entity = new Entity();
		$this->convertEntityBaseAttributes($collection, $entity);
		$this->convertFieldMap($collection, $entity);
		$this->convertRelationMap($collection, $entity);

		return $entity;
	}

	public function convertFieldMap(Collection $collection, Entity $entity): void
	{
		$on_fields = $collection->fields;
		$cycle_fields = $entity->getFields();
		foreach ($on_fields as $key => $on_field) {
			$field = $this->convertField($on_field, "");
			$field->setEntityClass($entity->getClass());
			$cycle_fields->set($key, $field);
		}
	}

	protected function convertField(Field $onField, $columnPrefix): CycleField
	{
		$field = new CycleField();

		$field->setType($this->resolveCycleFieldType($onField));

		$columnName = $columnPrefix . $onField->getColumn();

		$field->setColumn($columnName);

		$field->setPrimary($onField->isPrimaryKey());

		// when the VALUE is generated, and not the FIELD
		if ($this->isOnInsertGeneratedField($field)) {
			$field->setGenerated(CycleGeneratedField::ON_INSERT);
		}

		$field->setTypecast($onField->getTypecast()); // was: $this->resolveTypecast($onField->getTypecast(), $class));

		if ($onField->isNullable()) {
			$field->getOptions()->set(Column::OPT_NULLABLE, true);
			$field->getOptions()->set(Column::OPT_DEFAULT, null);
		}

		if ($onField->hasDefault()) {
			$field->getOptions()->set(Column::OPT_DEFAULT, $onField->getDefault());
		}

		if ($onField->castDefault()) {
			$field->getOptions()->set(Column::OPT_CAST_DEFAULT, true);
		}

		/*if ($onField->isReadonlySchema()) {
			$field->getAttributes()->set('readonlySchema', true);
		}*/

		/*foreach ($column->getAttributes() as $k => $v) {
			$field->getAttributes()->set($k, $v);
		}*/

		return $field;
	}

	/**
	 * Attributes to be converted:
	 * name => role
	 * entity => class
	 * mapper => mapper
	 * scope => scope
	 * source => source
	 *
	 */
	protected function convertEntityBaseAttributes(Collection $collection, Entity $entity): void
	{
		$convertMap = [
			"setRole" => "getName",
			"setClass" => "getEntity",
			"setMapper" => "getMapper",
			"setScope" => "getScope",
			"setRepository" => "getRepository",
			"setSource" => "getSource",
			"setDatabase" => "getDatabase",
			"setTableName" => "getTable",
		];

		$this->convertUsingMapArray($collection, $entity, $convertMap);
	}

	protected function convertUsingMapArray($from, $to, $convertMap): void
	{
		foreach ($convertMap as $setter => $getter) {
			$to->{$setter}($from->{$getter}());
		}
	}

	protected function convertRelationMap(Collection $collection, Entity $entity): void
	{
		$on_relations = $collection->relations;
		$cycle_relations = $entity->getRelations();

		foreach ($on_relations as $name => $on_relation) {

			$cycle_relation = $this->convertRelation($on_relation);
			$cycle_relations->set(
				$name,
				$cycle_relation
			);
		}
	}

	protected function convertRelation(RelationInterface $on_relation): CycleRelation
	{
		$cycle_relation = new CycleRelation();
		$cycle_relation
			->setTarget($on_relation->getCollectionName())
			->setType($this->resolveRelationType($on_relation));

		// TODO: convert options map
		$this->convertOptionMap($on_relation, $cycle_relation);

		return $cycle_relation;
	}

	protected function convertOptionMap(RelationInterface $on_relation, CycleRelation $cycle_relation): void
	{
		// reference: https://cycle-orm.dev/docs/relation-refers-to/current/en
		$options = $cycle_relation->getOptions()
			->set("load", $on_relation->getLoadStrategy())
			->set("cascade", $on_relation->isCascade())
			->set("nullable", $on_relation->isNullable())
			->set("innerKey", $this->relationColumns($on_relation->getParent(), $on_relation->innerKeys()))
			->set("outerKey", $this->relationColumns($on_relation->getCollection(), $on_relation->outerKeys()));

		if ($on_relation instanceof M2MRelation) {
			$options
				->set('through', $on_relation->through->getCollection()->getName())
				->set('throughInnerKey', $this->relationColumns($on_relation->through->getCollection(), $on_relation->through->throughInnerKeys()))
				->set('throughOuterKey', $this->relationColumns($on_relation->through->getCollection(), $on_relation->through->throughOuterKeys()));
		}
	}

	private function relationColumns(Collection $collection, array $keys): string|array
	{
		$columns = array_map(
			static fn(string $key): string => $collection->fields->get($key)->getColumn(),
			$keys
		);

		return count($columns) === 1 ? $columns[0] : $columns;
	}

	/**
	 * possible values: 'embedded', 'belongsTo','hasOne','hasMany','refersTo','manyToMany','belongsToMorphed','morphedHasOne','morphedHasMany'
	 */
	protected function resolveRelationType(RelationInterface $relation): string
	{
		if ($relation instanceof \ON\ORM\Definition\Relation\FirstOfManyRelation) {
			return 'hasMany';
		}

		if ($relation instanceof M2MRelation) {
			return 'manyToMany';
		}

		$class = new ReflectionClass($relation);
		$name = $class->getShortName();
		$name = str_replace("Relation", "", $name);

		if ($relation instanceof HasOneRelation) {
			if ($relation->isExclusive()) {
				$name = "hasOne";
			} else {
				if ($relation->isNullable()) {
					$name = "belongsTo";
				} else {
					$name = "refersTo";
				}
			}
		}

		return lcfirst($name);
	}

	private function isOnInsertGeneratedField(CycleField $field): bool
	{
		return match ($field->getType()) {
			'serial', 'bigserial', 'smallserial' => true,
			default => $field->isPrimary(),
		};
	}

	private function resolveCycleFieldType(Field $onField): string
	{
		$type = $onField->getType();

		if (class_exists($type) && is_subclass_of($type, FieldTypeInterface::class)) {
			$type = $type::storageType();
		}

		// Existing arbitrary classes are valid mapper/runtime types, but they are
		// not valid Cycle schema column types unless they explicitly implement a
		// field handler or map through known enum/datetime conventions below.
		if (
			class_exists($type)
			&& ! is_subclass_of($type, FieldTypeInterface::class)
			&& ! is_subclass_of($type, \DateTimeInterface::class)
			&& ! enum_exists($type)
		) {
			$this->assertKnownCycleFieldType($type, $onField);
		}

		$fieldType = (new FieldTypeRegistry())->resolve(FieldContext::fromField($onField));
		if ($fieldType !== null && ! $this->isKnownCycleFieldType($type)) {
			$type = $fieldType::storageType();
		}

		if (str_contains($type, '(')) {
			$this->assertKnownCycleFieldType($this->baseCycleFieldType($type), $onField);

			return $type;
		}

		$this->assertKnownCycleFieldType($type, $onField);

		if (strtolower($type) === 'string') {
			return sprintf('string(%d)', $onField->getMaxLength());
		}

		return $type;
	}

	private function baseCycleFieldType(string $type): string
	{
		return explode('(', $type, 2)[0];
	}

	private function assertKnownCycleFieldType(string $type, Field $onField): void
	{
		if (! $this->isKnownCycleFieldType($type)) {
			throw new FieldException(sprintf(
				'Field(%s) type "%s" is not a known Cycle column type in collection: %s',
				$onField->getName(),
				$onField->getType(),
				$onField->end()->getName(),
			));
		}
	}

	private function isKnownCycleFieldType(string $type): bool
	{
		return in_array(strtolower($type), self::KNOWN_CYCLE_FIELD_TYPES, true);
	}
}
