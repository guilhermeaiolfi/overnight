<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\FieldInterface;

abstract class AbstractRestResolver implements RestResolverInterface
{
	public function __construct(
		protected int $defaultLimit = 100,
		protected int $maxLimit = 1000
	) {
	}

	public function getPrimaryKeyColumn(CollectionInterface $collection): string
	{
		$pk = $collection->getPrimaryKey();

		if ($pk instanceof FieldInterface) {
			return $pk->getColumn();
		}

		if (is_array($pk) && !empty($pk)) {
			return $pk[0]->getColumn();
		}

		return 'id';
	}

	public function getVisibleFields(CollectionInterface $collection): array
	{
		$visible = [];
		foreach ($collection->fields as $field) {
			if (!$field->isHidden()) {
				$visible[] = $field->getColumn();
			}
		}

		return $visible;
	}

	public function getStringFields(CollectionInterface $collection): array
	{
		$stringTypes = ['string', 'text', 'varchar', 'char', 'longtext', 'mediumtext', 'tinytext'];
		$fields = [];

		foreach ($collection->fields as $name => $field) {
			if ($field->isHidden() || $field->isPrimaryKey()) {
				continue;
			}

			if (method_exists($field, 'isSearchable') && $field->isSearchable() === false) {
				continue;
			}

			try {
				if (in_array(strtolower($field->getType()), $stringTypes, true)) {
					$fields[] = $name;
				}
			} catch (\Throwable) {
			}
		}

		return $fields;
	}

	protected function parseArrayValue(mixed $value): array
	{
		if (is_array($value)) {
			return $value;
		}

		return array_map('trim', explode(',', (string) $value));
	}

	protected function formatAggregateResult(array $rows, array $aggregates, array $groupBy): array
	{
		$groupAliases = $this->getGroupByAliases($groupBy);
		$result = [];
		foreach ($rows as $row) {
			$entry = [];

			foreach ($groupBy as $field) {
				$alias = $groupAliases[$field] ?? $field;
				if (array_key_exists($alias, $row)) {
					$entry[$field] = $row[$alias];
				}
			}

			foreach ($aggregates as $func => $fields) {
				$fieldList = is_array($fields) ? $fields : [$fields];
				foreach ($fieldList as $field) {
					$alias = $this->aggregateAlias((string) $func, (string) $field);
					if (array_key_exists($alias, $row)) {
						$entry[$func][$field] = $row[$alias];
					}
				}
			}

			$result[] = $entry;
		}

		return $result;
	}

	protected function aggregateAlias(string $function, string $field): string
	{
		return preg_replace('/[^a-zA-Z0-9_]/', '_', $function . '_' . $field);
	}

	protected function getGroupByAliases(array $groupBy): array
	{
		$aliases = [];
		foreach ($groupBy as $field) {
			$aliases[$field] = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $field);
		}

		return $aliases;
	}
}
