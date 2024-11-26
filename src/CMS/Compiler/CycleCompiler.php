<?php

declare(strict_types=1);

namespace ON\CMS\Compiler;

use Cycle\ORM\Mapper\StdMapper;
use Cycle\ORM\Relation as CycleRelation;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use ON\CMS\Definition\CollectionDefinition;
use ON\CMS\Definition\Relation\M2MRelation;
use ON\CMS\Definition\Relation\O2MRelation;
use ON\CMS\Definition\Relation\O2ORelation;
use ON\CMS\Definition\Relation\Relation;
use StdClass;

class CycleCompiler
{
	public function __construct(
		protected array $collections
	) {

	}

	public function compile(): SchemaInterface
	{
		$schema = [];
		foreach ($$this->collections as $collection) {
			$schema[$collection->name] = $this->processCollection($collection);
		}

		return new Schema($schema);
	}

	public function processCollection(CollectionDefinition $collection): array
	{
		$result = [
			SchemaInterface::ROLE => $collection->getName(),
			SchemaInterface::ENTITY => $collection->getEntity(),
			SchemaInterface::MAPPER => $collection->getMapper(),
			SchemaInterface::DATABASE => 'default',
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

    public function processScope(CollectionDefinition $collection): array
    {
        // TODO
        return [];
    }

	public function processRelations(CollectionDefinition $collection): array
	{
		$relations = [];
		foreach ($collection->getRelations() as $relation) {
			$relation[$relation->getName()] = $this->getRelationSchema($relation);
		}

		return $relations;
	}

    public function getLoadStrategy(Relation $relation): int
    {
        return $relation->getLoadStrategy() == "load"? 
            CycleRelation::LOAD_EAGER : CycleRelation::LOAD_PROMISE
    }

	public function getRelationSchema(Relation $relation): array
	{
		if ($relation instanceof M2MRelation) {
			/** @var M2MRelation $relation */
			return [
				CycleRelation::TYPE => CycleRelation::MANY_TO_MANY,
                CycleRelation::LOAD  =>  $this->getLoadStrategy($relation),
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

		} elseif ($relation instanceof O2MRelation) {
			/** @var O2MRelation $relation */
			return [
				CycleRelation::TYPE => CycleRelation::HAS_MANY,
                CycleRelation::LOAD  =>  $this->getLoadStrategy($relation),
				CycleRelation::TARGET => $relation->getCollection(),
				CycleRelation::SCHEMA => [
					CycleRelation::NULLABLE => $relation->isNullable(),
					CycleRelation::CASCADE => $relation->isCascade(),
					CycleRelation::INNER_KEY => $relation->getInnerKey(),
					CycleRelation::OUTER_KEY => $relation->getOuterKey(),
				],
			];
		} elseif ($relation instanceof O2ORelation) {
			/** @var O2ORelation $relation */
			if ($relation->isExclusive()) {
				// TODO
			} else {
				return [
					CycleRelation::TYPE => $relation->isNullable() ? CycleRelation::BELONGS_TO : CycleRelation::REFERS_TO,
                    CycleRelation::LOAD  =>  $this->getLoadStrategy($relation),
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

		return $schema;
	}

	public function processSchema(CollectionDefinition $collection): array
	{
		return [];
	}

	public function processFields(CollectionDefinition $collection): array
	{
		$columns = [];
		foreach ($collection->fields as $field) {
			$columns[$field->getName()] = $field->getColumn();
		}

		return $columns;
	}

	public function processTypecast(CollectionDefinition $collection): array
	{
		$columns = [];
		foreach ($collection->fields as $field) {
            if ($field->hasTypecast()) {
			    $columns[$field->getName()] = $field->getTypecast();
            }
		}

		return $columns;
	}

	public function getPrimaryKey(CollectionDefinition $collection): mixed
	{
		$pks = $collection->getPrimaryKey();
		$result = [];
		if (is_array($pks)) {
			foreach ($pks as $pk) {
				$result[] = $pk->name;
			}
		} else {
			$result[] = $pks->name;
		}
		if (count($result) == 1) {
			return $result[0];
		}

		return $result;
	}
}
