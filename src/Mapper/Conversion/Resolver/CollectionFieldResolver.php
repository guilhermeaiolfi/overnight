<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion\Resolver;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Mapper\Conversion\ConversionDirection;
use ON\Mapper\Conversion\FieldResolverInterface;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Structural\MappingContext;

/** Resolves FieldContext from a CollectionInterface present in mapper args. */
final class CollectionFieldResolver implements FieldResolverInterface
{
	private int|string|null $collectionArgIndex = null;
	private bool $firstRun = true;
	private bool $capable = true;

	public function resolve(
		MappingContext $mapping,
		string $path,
		string $fieldName,
		mixed $value,
		ConversionDirection $direction,
		mixed $extra = null,
	): ?FieldContext {
		$collection = $this->resolveCollection($mapping);
		if ($collection === null || ! $collection->fields->has($fieldName)) {
			return null;
		}

		return FieldContext::fromField($collection->fields->get($fieldName));
	}

	private function resolveCollection(MappingContext $mapping): ?CollectionInterface
	{
		if (! $this->capable) {
			return null;
		}

		if ($this->firstRun) {
			$this->firstRun = false;
			foreach ($mapping->args as $index => $arg) {
				if ($arg instanceof CollectionInterface) {
					$this->collectionArgIndex = $index;

					break;
				}
			}

			$this->capable = $this->collectionArgIndex !== null;
		}

		if (! $this->capable || $this->collectionArgIndex === null) {
			return null;
		}

		$collection = $mapping->args[$this->collectionArgIndex] ?? null;

		return $collection instanceof CollectionInterface ? $collection : null;
	}
}
