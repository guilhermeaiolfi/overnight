<?php

declare(strict_types=1);

namespace ON\Mapper;

use ON\Mapper\Blueprint\MappingBlueprint;
use ON\Mapper\Representation\RepresentationResolver;
use ON\Mapper\Structural\MapperInterface;
use ON\Mapper\Structural\MappingContext;
use InvalidArgumentException;

final class MapBuilder
{
	public function __construct(
		private readonly mixed $source,
		private readonly ConversionGateway $gateway,
		/** @var class-string|null */
		private readonly ?string $sourceRepresentation = null,
		/** @var class-string|null */
		private readonly ?string $propertyRepresentation = null,
		/** @var class-string|null */
		private readonly ?string $outputRepresentation = null,
		private readonly ?string $mapperClass = null,
		private readonly array $mapperArgs = [],
		private readonly bool $asCollection = false,
		private readonly ?MappingBlueprint $blueprint = null,
	) {
	}

	/**
	 * @param class-string|null $representation
	 */
	public function from(?string $representation): self
	{
		return new self(
			$this->source,
			$this->gateway,
			RepresentationResolver::resolve($representation) ?? $this->sourceRepresentation,
			$this->propertyRepresentation,
			$this->outputRepresentation,
			$this->mapperClass,
			$this->mapperArgs,
			$this->asCollection,
			$this->blueprint,
		);
	}

	/**
	 * Target value encoding after field conversion.
	 *
	 * @param class-string|null $representation
	 */
	public function as(?string $representation): self
	{
		$resolved = RepresentationResolver::resolve($representation);

		return new self(
			$this->source,
			$this->gateway,
			$this->sourceRepresentation,
			$resolved ?? $this->propertyRepresentation,
			$resolved ?? $this->outputRepresentation,
			$this->mapperClass,
			$this->mapperArgs,
			$this->asCollection,
			$this->blueprint,
		);
	}

	/**
	 * Declares field types (and optional structural mappers) for untyped targets such as stdClass.
	 */
	public function blueprint(MappingBlueprint $blueprint): self
	{
		return new self(
			$this->source,
			$this->gateway,
			$this->sourceRepresentation,
			$this->propertyRepresentation,
			$this->outputRepresentation,
			$this->mapperClass,
			$this->mapperArgs,
			$this->asCollection,
			$blueprint,
		);
	}

	/**
	 * @param class-string|string $target
	 */
	public function to(string $target, ?string $sourceOverride = null): mixed
	{
		if (RepresentationResolver::isRepresentationHint($target)) {
			throw new InvalidArgumentException(sprintf(
				'Use as(%s::class) to set the target value encoding; to() is reserved for structural targets.',
				$target,
			));
		}

		$context = $this->buildMappingContext();
		if ($sourceOverride !== null) {
			$context = $context->withSourceRepresentation(RepresentationResolver::resolve($sourceOverride));
		}

		if ($this->asCollection) {
			$context = $context->asCollection();
		}

		return $this->gateway->structuralMappers()->map($this->source, $target, $context);
	}

	/**
	 * @param class-string<MapperInterface> $mapperClass
	 */
	public function using(string $mapperClass, mixed ...$args): self
	{
		return new self(
			$this->source,
			$this->gateway,
			$this->sourceRepresentation,
			$this->propertyRepresentation,
			$this->outputRepresentation,
			$mapperClass,
			$args,
			$this->asCollection,
			$this->blueprint,
		);
	}

	public function collection(): self
	{
		return new self(
			$this->source,
			$this->gateway,
			$this->sourceRepresentation,
			$this->propertyRepresentation,
			$this->outputRepresentation,
			$this->mapperClass,
			$this->mapperArgs,
			true,
			$this->blueprint,
		);
	}

	/**
	 * @return array<string, mixed>|list<array<string, mixed>>
	 */
	public function toArray(): array
	{
		$context = $this->buildMappingContext();

		if ($this->asCollection) {
			$context = $context->asCollection();
		}

		/** @var array<string, mixed>|list<array<string, mixed>> */
		return $this->gateway->structuralMappers()->map($this->source, 'array', $context);
	}

	public function toJson(): string
	{
		return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
	}

	private function buildMappingContext(): MappingContext
	{
		$context = new MappingContext(
			$this->gateway,
			$this->sourceRepresentation,
			$this->propertyRepresentation,
			$this->outputRepresentation,
			$this->mapperClass,
			$this->mapperArgs,
			blueprint: $this->blueprint,
		);

		return $this->applyMapperDefaults($context);
	}

	private function applyMapperDefaults(MappingContext $context): MappingContext
	{
		if ($context->mapperClass === null) {
			return $context;
		}

		foreach ($this->gateway->structuralMappers()->all() as $mapper) {
			if ($mapper::class !== $context->mapperClass) {
				continue;
			}

			$defaults = $mapper->defaultRepresentations();
			$targetRepresentation = $defaults['as'] ?? $defaults['to'] ?? null;

			return new MappingContext(
				$context->gateway,
				$context->sourceRepresentation ?? $defaults['from'] ?? null,
				$context->propertyRepresentation ?? $defaults['property'] ?? $targetRepresentation,
				$context->outputRepresentation ?? $targetRepresentation,
				$context->mapperClass,
				$context->mapperArgs,
				$context->collection,
				$context->blueprint,
			);
		}

		return $context;
	}
}
