<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion;

use ON\Mapper\ConversionGateway;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Structural\MappingContext;

/**
 * Converts one scalar when the mapper has already resolved FieldContext.
 * Optionally tries map()->resolver() override before the mapper's own resolution.
 */
final class FieldConversionCoordinator
{
	public function __construct(
		private readonly ConversionGateway $gateway,
	) {
	}

	public function resolveOverride(
		MappingContext $mapping,
		string $path,
		string $fieldName,
		mixed $value,
		ConversionDirection $direction,
	): ?FieldContext {
		$override = $this->instantiateOverride($mapping);
		if ($override === null) {
			return null;
		}

		return $override->resolve($mapping, $path, $fieldName, $value, $direction);
	}

	public function convertScalar(
		mixed $value,
		FieldContext $field,
		MappingContext $mapping,
		ConversionDirection $direction,
	): mixed {
		return $this->gateway->convertScalar($value, $field, $mapping, $direction);
	}

	private function instantiateOverride(MappingContext $mapping): ?ScalarFieldResolverOverrideInterface
	{
		if ($mapping->resolverClass === null) {
			return null;
		}

		$class = $mapping->resolverClass;
		if (! is_subclass_of($class, ScalarFieldResolverOverrideInterface::class)) {
			throw new \InvalidArgumentException(sprintf(
				'Resolver %s must implement %s.',
				$class,
				ScalarFieldResolverOverrideInterface::class,
			));
		}

		if ($mapping->resolverArgs === []) {
			return new $class();
		}

		return new $class(...$mapping->resolverArgs);
	}
}
