<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver;

use ON\ORM\Definition\Collection\CollectionInterface;

abstract class AbstractDataSource implements DataSourceInterface
{
	public function __construct(
		protected int $defaultLimit = 100,
		protected int $maxLimit = 1000
	) {
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

	protected function aggregateAlias(string $function, string $field): string
	{
		return preg_replace('/[^a-zA-Z0-9_]/', '_', $function . '_' . $field);
	}
}
