<?php

declare(strict_types=1);

namespace ON\ORM\Compiler;

use Cycle\ORM\Relation as CycleRelation;
use Cycle\ORM\SchemaInterface;
use JetBrains\PhpStorm\Deprecated;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\HasManyRelation;
use ON\ORM\Definition\Relation\HasOneRelation;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\ORM\Definition\Relation\RelationInterface;

/**
 * It converts an overnight Registry into a \Cycle\ORM\Schema.
 * As documented here: https://cycle-orm.dev/docs/schema-manual/current/en
 * That was the old way of using it. Since sometime, overnigh
 * converts to Cycle Registry. The advantage is that it can generate not only
 * the cycle schema, but migrations and many other things.
 *
 * Basically, use CycleEntityGenerator instead.
 *
 */
#[Deprecated(reason:"CycleEntityGenerator fits better with Cycle", replacement: "use CycleEntityGenerator instead")]
class CycleCompiler
{
	public function __construct(
		protected Registry $registry
	) {

	}

	public function compile(): array
	{
		$schema = [];
		$collections = $this->registry->getCollections();
		foreach ($collections as $collection) {
			$schema[$collection->getName()] = $this->processCollection($collection);
		}

		return $schema;
	}

	public function processCollection(CollectionInterface $collection): array
	{
		$result = [
			SchemaInterface::ROLE => $collection->getName(),
			SchemaInterface::ENTITY => $collection->getEntity(),
			SchemaInterface::MAPPER => $collection->getMapper(),
			SchemaInterface::DATABASE => $collection->getDatabase(),
			SchemaInterface::TABLE => $collection->getName(),
			SchemaInterface::PRIMARY_KEY => $this->getPrimaryKey($collection),
			SchemaInterface::COLUMNS => $this->processFields($collection),
			SchemaInterface::TYPECAST => $this->processTypecast($collection),
			SchemaInterface::SCHEMA => $this->processSchema($collection),
			SchemaInterface::RELATIONS => $this->processRelations($collection),
			SchemaInterface::SCOPE => $this->processScope($collection),
		];

		return $result;
	}

	public function processScope(CollectionInterface $collection): ?array
	{
		// TODO
		return null;
	}

	public function processRelations(CollectionInterface $collection): array
	{
		$relations = [];
		foreach ($collection->relations as $relation) {
			$relations[$relation->getName()] = $this->getRelationSchema($relation);
		}

		return $relations;
	}

	public function getLoadStrategy(RelationInterface $relation): int
	{
		return $relation->getLoadStrategy() == "load" ?
			CycleRelation::LOAD_EAGER : CycleRelation::LOAD_PROMISE;
	}

	public function getRelationSchema(RelationInterface $relation): array
	{
		if ($relation instanceof M2MRelation) {
			/** @var M2MRelation $relation */
			return [
				CycleRelation::TYPE => CycleRelation::MANY_TO_MANY,
				CycleRelation::LOAD => $this->getLoadStrategy($relation),
				CycleRelation::TARGET => $relation->getCollection(),
				CycleRelation::SCHEMA => [
					CycleRelation::NULLABLE => $relation->isNullable(),
					CycleRelation::CASCADE => $relation->isCascade(),
					CycleRelation::INNER_KEY => $relation->getInnerKey(),
					CycleRelation::OUTER_KEY => $relation->getOuterKey(),
					CycleRelation::THROUGH_ENTITY => $relation->through->getCollection(),
					CycleRelation::THROUGH_INNER_KEY => $relation->through->getInnerKey(),
					CycleRelation::THROUGH_OUTER_KEY => $relation->through->getOuterKey(),
				],
			];

		} elseif ($relation instanceof HasManyRelation) {
			/** @var HasManyRelation $relation */
			return [
				CycleRelation::TYPE => CycleRelation::HAS_MANY,
				CycleRelation::LOAD => $this->getLoadStrategy($relation),
				CycleRelation::TARGET => $relation->getCollection(),
				CycleRelation::SCHEMA => [
					CycleRelation::NULLABLE => $relation->isNullable(),
					CycleRelation::CASCADE => $relation->isCascade(),
					CycleRelation::INNER_KEY => $relation->getInnerKey(),
					CycleRelation::OUTER_KEY => $relation->getOuterKey(),
				],
			];
		} elseif ($relation instanceof HasOneRelation) {
			/** @var HasOneRelation $relation */
			if ($relation->isExclusive()) {
				return [
					CycleRelation::TYPE => CycleRelation::HAS_ONE,
					CycleRelation::LOAD => $this->getLoadStrategy($relation),
					CycleRelation::TARGET => $relation->getCollection(),
					CycleRelation::SCHEMA => [
						CycleRelation::NULLABLE => $relation->isNullable(),
						CycleRelation::CASCADE => $relation->isCascade(),
						CycleRelation::INNER_KEY => $relation->getInnerKey(),
						CycleRelation::OUTER_KEY => $relation->getOuterKey(),
					],
				];
			} else {
				return [
					CycleRelation::TYPE => $relation->isNullable() ? CycleRelation::BELONGS_TO : CycleRelation::REFERS_TO,
					CycleRelation::LOAD => $this->getLoadStrategy($relation),
					CycleRelation::TARGET => $relation->getCollection(),
					CycleRelation::SCHEMA => [
						CycleRelation::NULLABLE => $relation->isNullable(),
						CycleRelation::CASCADE => $relation->isCascade(),
						CycleRelation::INNER_KEY => $relation->getInnerKey(),
						CycleRelation::OUTER_KEY => $relation->getOuterKey(),
					],
				];
			}
		}

		return [];
	}

	public function processSchema(CollectionInterface $collection): array
	{
		return [];
	}

	public function processFields(CollectionInterface $collection): array
	{
		$columns = [];
		foreach ($collection->fields as $field) {
			$columns[$field->getName()] = $field->getColumn();
		}

		return $columns;
	}

	public function processTypecast(CollectionInterface $collection): array
	{
		$columns = [];
		foreach ($collection->fields as $field) {
			if ($field->hasTypecast()) {
				$columns[$field->getName()] = $field->getTypecast();
			}
		}

		return $columns;
	}

	public function getPrimaryKey(CollectionInterface $collection): mixed
	{
		$pks = $collection->getPrimaryKey();
		$result = [];
		if (is_array($pks)) {
			foreach ($pks as $pk) {
				$result[] = $pk->getName();
			}
		} else {
			$result[] = $pks->getName();
		}
		if (count($result) == 1) {
			return $result[0];
		}

		return $result;
	}
}
