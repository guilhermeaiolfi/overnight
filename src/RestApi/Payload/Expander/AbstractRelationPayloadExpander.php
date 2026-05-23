<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Expander;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Relation\RelationInterface;
use ON\RestApi\Resolver\Sql\SqlDataSource;

abstract class AbstractRelationPayloadExpander
{
	use RelationExpanderSupport;

	public function __construct(
		protected CollectionInterface $collection,
		protected RelationInterface $relation,
		protected SqlDataSource $dataSource,
	) {
	}

	protected function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	protected function getTargetCollection(): CollectionInterface
	{
		return $this->relation->getCollection();
	}

	protected function visibleFieldNames(CollectionInterface $collection): array
	{
		$visible = [];
		foreach ($collection->fields as $fieldName => $field) {
			if (!$field->isHidden()) {
				$visible[] = (string) $fieldName;
			}
		}

		return $visible;
	}

	protected function mapRowToFieldNames(CollectionInterface $collection, array $row): array
	{
		$item = [];
		foreach ($row as $column => $value) {
			$name = $collection->fields->hasColumn((string) $column)
				? $collection->fields->getKeyByColumnName((string) $column)
				: (string) $column;

			$item[$name] = $value;
		}

		return $item;
	}
}
