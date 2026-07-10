<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion\Resolver;

use DateTimeInterface;
use ON\Mapper\Blueprint\MappingBlueprint;
use ON\Mapper\Conversion\ConversionDirection;
use ON\Mapper\Conversion\FieldResolverInterface;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Structural\MappingContext;

/** Resolves FieldContext from a MappingBlueprint present in mapper args. */
final class BlueprintFieldResolver implements FieldResolverInterface
{
	private int|string|null $blueprintArgIndex = null;
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
		$blueprint = $this->resolveBlueprint($mapping);
		if ($blueprint === null) {
			return null;
		}

		$entry = $blueprint->resolve($path);
		if ($entry === null) {
			return null;
		}

		if (class_exists($entry->type)
			&& ! enum_exists($entry->type)
			&& ! is_subclass_of($entry->type, DateTimeInterface::class)
		) {
			return null;
		}

		return FieldContext::named(
			$fieldName,
			$entry->type,
			$value === null,
		);
	}

	private function resolveBlueprint(MappingContext $mapping): ?MappingBlueprint
	{
		if (! $this->capable) {
			return null;
		}

		if ($this->firstRun) {
			$this->firstRun = false;
			foreach ($mapping->args as $index => $arg) {
				if ($arg instanceof MappingBlueprint) {
					$this->blueprintArgIndex = $index;

					break;
				}
			}

			$this->capable = $this->blueprintArgIndex !== null;
		}

		if (! $this->capable || $this->blueprintArgIndex === null) {
			return null;
		}

		$blueprint = $mapping->args[$this->blueprintArgIndex] ?? null;

		return $blueprint instanceof MappingBlueprint ? $blueprint : null;
	}
}
