<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\ORM\Definition\Collection\CollectionInterface;

class FieldSelector
{
	/**
	 * Parse the `fields` query parameter into field list and relation map.
	 *
	 * Input: "id,name,author.name,author.role.name"
	 * Output: [
	 *   'fields' => ['id', 'name'],
	 *   'relations' => [
	 *     'author' => ['fields' => ['name'], 'relations' => ['role' => ['fields' => ['name']]]]
	 *   ]
	 * ]
	 */
	public function parse(CollectionInterface $collection, ?string $fieldsParam, array $aliases = []): array
	{
		$aliases = $this->normalizeAliases($collection, $aliases);

		if ($fieldsParam === null || $fieldsParam === '' || $fieldsParam === '*') {
			return [
				'fields' => $this->getAllVisibleFields($collection),
				'relations' => [],
			];
		}

		$fieldPaths = array_map('trim', explode(',', $fieldsParam));

		return $this->buildTree($fieldPaths, $collection, $aliases);
	}

	protected function buildTree(array $fieldPaths, CollectionInterface $collection, array $aliases = []): array
	{
		$fields = [];
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
					$fields[] = $first;
				}
			} else {
				// Relation path
				if ($this->validateRelation($collection, $first) || isset($aliases[$first])) {
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
			$targetRelationName = $aliases[$relationName] ?? $relationName;
			$relation = $collection->relations->get($targetRelationName);
			$targetCollectionName = $relation->getCollection();
			$registry = $collection->getRegistry();
			$targetCollection = $registry->getCollection($targetCollectionName);

			if ($targetCollection === null) {
				continue;
			}

			$resolvedRelations[$relationName] = $this->buildTree($subPaths, $targetCollection);
			if ($targetRelationName !== $relationName) {
				$resolvedRelations[$relationName]['_relation'] = $targetRelationName;
			}
		}

		// Ensure primary key is always included
		$pk = $collection->getPrimaryKey();
		if ($pk !== null) {
			$pkName = is_array($pk) ? $pk[0]->getName() : $pk->getName();
			if (!in_array($pkName, $fields, true)) {
				array_unshift($fields, $pkName);
			}
		}

		return [
			'fields' => array_unique($fields),
			'relations' => $resolvedRelations,
		];
	}

	protected function getAllVisibleFields(CollectionInterface $collection): array
	{
		$fields = [];
		foreach ($collection->fields as $name => $field) {
			if (!$field->isHidden()) {
				$fields[] = $name;
			}
		}

		return $fields;
	}

	protected function validateField(CollectionInterface $collection, string $fieldName): bool
	{
		return $collection->fields->has($fieldName);
	}

	protected function validateRelation(CollectionInterface $collection, string $relationName): bool
	{
		return $collection->relations->has($relationName);
	}

	protected function normalizeAliases(CollectionInterface $collection, array $aliases): array
	{
		$normalized = [];

		foreach ($aliases as $alias => $target) {
			if (!is_string($alias) || !is_string($target)) {
				continue;
			}

			if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
				continue;
			}

			if ($collection->fields->has($alias) || $collection->relations->has($alias)) {
				continue;
			}

			if (!$collection->relations->has($target)) {
				continue;
			}

			$normalized[$alias] = $target;
		}

		return $normalized;
	}
}
