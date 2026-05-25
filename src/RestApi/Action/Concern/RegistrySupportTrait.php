<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Concern;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Support\PrimaryKeyCriteria;

trait RegistrySupportTrait
{
	protected function getCollection(string|CollectionInterface $collectionName): CollectionInterface
	{
		$collection = $this->registry->getCollection($collectionName);

		if ($collection === null || $collection->isHidden()) {
			throw RestApiError::collectionNotFound(is_string($collectionName) ? $collectionName : $collectionName->getName());
		}

		return $collection;
	}

	protected function decodeRouteIdentity(CollectionInterface $collection, string $id): PrimaryKeyValue
	{
		return $collection->getPrimaryKey()->isComposite()
			? $collection->getPrimaryKey()->getValueFromUrlId($id)
			: new PrimaryKeyValue($collection, [$collection->getPrimaryKey()->getFieldNames()[0] => $id]);
	}

	protected function getInputPrimaryKeyValue(CollectionInterface $collection, array $input): ?PrimaryKeyValue
	{
		return $collection->getPrimaryKey()->extractFromInput($input);
	}

	protected function normalizeIdentity(CollectionInterface $collection, PrimaryKeyValue|string $identity): PrimaryKeyValue
	{
		return PrimaryKeyCriteria::normalize($collection, $identity);
	}

	protected function stripHiddenFields(CollectionInterface $collection, array $input): array
	{
		foreach ($collection->fields as $name => $field) {
			if ($field->isHidden() && array_key_exists($name, $input)) {
				unset($input[$name]);
			}
		}

		return $input;
	}

	protected function fieldNamesToColumnNames(CollectionInterface $collection, array $fieldNames): array
	{
		$columnNames = [];
		foreach ($fieldNames as $fieldName) {
			$fieldName = (string) $fieldName;
			if (!$collection->fields->has($fieldName)) {
				throw RestApiError::invalidField($fieldName);
			}

			$columnNames[] = $collection->fields->get($fieldName)->getColumn();
		}

		return array_values(array_unique($columnNames));
	}

	protected function columnNamesToFieldNames(CollectionInterface $collection, array $columnNames): array
	{
		$fieldNames = [];
		foreach ($columnNames as $columnName) {
			$fieldNames[] = $collection->getFieldNameByColumn($columnName);
		}

		return array_values(array_unique($fieldNames));
	}

	protected function getRelationKeyColumnNames(CollectionInterface $collection, array $relations): array
	{
		$columnNames = [];
		foreach ($relations as $relation) {
			if ($relation instanceof RelationSelection && $collection->relations->has($relation->relationName)) {
				foreach ($collection->relations->get($relation->relationName)->innerKeys() as $fieldName) {
					$columnNames[] = $collection->fields->get($fieldName)->getColumn();
				}
			}
		}

		return array_values(array_unique($columnNames));
	}
}
