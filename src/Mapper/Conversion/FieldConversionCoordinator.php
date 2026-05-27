<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion;

use ON\Mapper\ConversionGateway;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Structural\MappingContext;

/**
 * Coordinates field-context resolution and scalar conversion for structural mappers.
 */
final class FieldConversionCoordinator
{
	/** @var list<FieldResolverInterface> */
	private array $resolvers = [];

	public function __construct(
		private readonly ConversionGateway $gateway,
	) {
	}

	public function register(FieldResolverInterface ...$resolvers): self
	{
		foreach ($resolvers as $resolver) {
			$this->resolvers[] = $resolver;
		}

		return $this;
	}

	public function registerConfiguredResolvers(MappingContext $mapping): self
	{
		foreach ($mapping->resolverDefinitions as $definition) {
			$class = $definition->class;
			if (! is_subclass_of($class, FieldResolverInterface::class)) {
				throw new \InvalidArgumentException(sprintf(
					'Resolver %s must implement %s.',
					$class,
					FieldResolverInterface::class,
				));
			}

			$this->register(new $class(...$definition->args));
		}

		return $this;
	}

	public function resolveField(
		MappingContext $mapping,
		string $path,
		string $fieldName,
		mixed $value,
		ConversionDirection $direction,
		mixed $extra = null,
	): ?FieldContext {
		foreach ($this->resolvers as $resolver) {
			$field = $resolver->resolve($mapping, $path, $fieldName, $value, $direction, $extra);
			if ($field !== null) {
				return $field;
			}
		}

		return null;
	}

	public function convertScalar(
		mixed $value,
		FieldContext $field,
		MappingContext $mapping,
		ConversionDirection $direction,
	): mixed {
		return $this->gateway->convertScalar($value, $field, $mapping, $direction);
	}

}
