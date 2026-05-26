<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Typecast\TypecastException;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Serialize\CollectionSerializer;

trait FormatOutputTrait
{
	protected ?CollectionSerializer $collectionSerializer = null;

	protected function collectionSerializer(): CollectionSerializer
	{
		return $this->collectionSerializer ??= new CollectionSerializer();
	}

	protected function serialize(CollectionInterface $collection, array $phpRow): array
	{
		return $this->collectionSerializer()->serialize($collection, $phpRow);
	}

	protected function unserialize(CollectionInterface $collection, string|array $payload, bool $partial = false): array
	{
		try {
			return $this->collectionSerializer()->unserialize($collection, $payload, $partial);
		} catch (TypecastException $e) {
			throw RestApiError::validationFailed([
				$e->getField() ?? '_root' => [$e->getMessage()],
			]);
		}
	}

	protected function formatResponseRow(CollectionInterface $collection, ?array $row, array $options): ?array
	{
		if ($row === null) {
			return null;
		}

		if ($row !== [] && !($options['raw'] ?? false)) {
			$row = $this->items->hydrateRow($collection, $row);
		}

		if ($options['serialize']) {
			return $this->serialize($collection, $row);
		}

		return $row;
	}

	protected function formatResponseRows(CollectionInterface $collection, array $rows, array $options): array
	{
		if ($rows === []) {
			return $rows;
		}

		return array_map(fn (array $row): array => $this->formatResponseRow($collection, $row, $options), $rows);
	}

}
