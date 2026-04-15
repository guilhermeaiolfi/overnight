<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\ORM\Definition\Collection\CollectionInterface;

class FieldSelector
{
	/**
	 * Parse the `fields` query parameter into column list and relation map.
	 *
	 * Input: "id,name,author.name,author.role.name"
	 * Output: [
	 *   'columns' => ['id', 'name'],
	 *   'relations' => [
	 *     'author' => ['columns' => ['name'], 'relations' => ['role' => ['columns' => ['name']]]]
	 *   ]
	 * ]
	 */
	public function parse(CollectionInterface $collection, ?string $fieldsParam): array
	{
		if ($fieldsParam === null || $fieldsParam === '' || $fieldsParam === '*') {
			return [
				'columns' => $this->getAllVisibleColumns($collection),
				'relations' => [],
			];
		}

		$fieldPaths = array_map('trim', explode(',', $fieldsParam));

		return $this->buildTree($fieldPaths, $collection);
	}

	protected function buildTree(array $fieldPaths, CollectionInterface $collection): array
	{
		$columns = [];
		$relations = [];

		foreach ($fieldPaths as $path) {
			if ($path === '') {
				continue;
			}

			$parts = explode('.', $path, 2);
			$first = $parts[0];

			if (count($parts) === 1) {
				// Scalar field
				if ($this->validateField($collection, $first)) {
					$columns[] = $first;
				}
			} else {
				// Relation path
				if ($this->validateRelation($collection, $first)) {
					if (!isset($relations[$first])) {
						$relations[$first] = [];
					}
					$relations[$first][] = $parts[1];
				}
			}
		}

		// Resolve nested relation trees
		$resolvedRelations = [];
		foreach ($relations as $relationName => $subPaths) {
			$relation = $collection->relations->get($relationName);
			$targetCollectionName = $relation->getCollection();
			$registry = $collection->getRegistry();
			$targetCollection = $registry->getCollection($targetCollectionName);

			if ($targetCollection === null) {
				continue;
			}

			$resolvedRelations[$relationName] = $this->buildTree($subPaths, $targetCollection);
		}

		// Ensure primary key is always included
		$pk = $collection->getPrimaryKey();
		if ($pk !== null) {
			$pkName = is_array($pk) ? $pk[0]->getName() : $pk->getName();
			if (!in_array($pkName, $columns, true)) {
				array_unshift($columns, $pkName);
			}
		}

		return [
			'columns' => array_unique($columns),
			'relations' => $resolvedRelations,
		];
	}

	protected function getAllVisibleColumns(CollectionInterface $collection): array
	{
		$columns = [];
		foreach ($collection->fields as $name => $field) {
			if (!$field->isHidden()) {
				$columns[] = $name;
			}
		}
		return $columns;
	}

	protected function validateField(CollectionInterface $collection, string $fieldName): bool
	{
		return $collection->fields->has($fieldName);
	}

	protected function validateRelation(CollectionInterface $collection, string $relationName): bool
	{
		return $collection->relations->has($relationName);
	}
}
