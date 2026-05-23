<?php

declare(strict_types=1);

namespace ON\RestApi\Mapping;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Typecast\CollectionTypecast;
use ON\ORM\Typecast\TypecastException;
use ON\RestApi\Error\RestApiError;

final class CollectionMapper implements CollectionMapperInterface
{
	public function __construct(
		private readonly CollectionTypecast $typecast = new CollectionTypecast(),
	) {
	}

	public function hydrate(CollectionInterface $collection, array $row): array
	{
		try {
			return $this->typecast->toPhp($collection, $row);
		} catch (TypecastException $e) {
			throw RestApiError::validationFailed([
				$e->getField() ?? '_root' => [$e->getMessage()],
			]);
		}
	}

	public function dehydrate(CollectionInterface $collection, array $row, bool $partial = false): array
	{
		try {
			return $this->typecast->fromPhp($collection, $row, partial: $partial);
		} catch (TypecastException $e) {
			throw RestApiError::validationFailed([
				$e->getField() ?? '_root' => [$e->getMessage()],
			]);
		}
	}
}
