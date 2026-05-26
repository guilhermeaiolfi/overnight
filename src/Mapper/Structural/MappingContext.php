<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\Blueprint\MappingBlueprint;
use ON\Mapper\ConversionGateway;
use ON\Mapper\Representation\RepresentationInterface;

final class MappingContext
{
	public function __construct(
		public readonly ConversionGateway $gateway,
		/** @var class-string<RepresentationInterface>|null */
		public readonly ?string $sourceRepresentation = null,
		/** @var class-string<RepresentationInterface>|null */
		public readonly ?string $propertyRepresentation = null,
		/** @var class-string<RepresentationInterface>|null */
		public readonly ?string $outputRepresentation = null,
		public readonly ?string $mapperClass = null,
		public readonly array $mapperArgs = [],
		public readonly bool $collection = false,
		public readonly ?MappingBlueprint $blueprint = null,
	) {
	}

	/**
	 * @param class-string<RepresentationInterface>|null $representation
	 */
	public function withSourceRepresentation(?string $representation): self
	{
		return new self(
			$this->gateway,
			$representation,
			$this->propertyRepresentation,
			$this->outputRepresentation,
			$this->mapperClass,
			$this->mapperArgs,
			$this->collection,
			$this->blueprint,
		);
	}

	/**
	 * @param class-string<RepresentationInterface>|null $representation
	 */
	public function withPropertyRepresentation(?string $representation): self
	{
		return new self(
			$this->gateway,
			$this->sourceRepresentation,
			$representation,
			$this->outputRepresentation,
			$this->mapperClass,
			$this->mapperArgs,
			$this->collection,
			$this->blueprint,
		);
	}

	/**
	 * @param class-string<RepresentationInterface>|null $representation
	 */
	public function withOutputRepresentation(?string $representation): self
	{
		return new self(
			$this->gateway,
			$this->sourceRepresentation,
			$this->propertyRepresentation,
			$representation,
			$this->mapperClass,
			$this->mapperArgs,
			$this->collection,
			$this->blueprint,
		);
	}

	public function withMapperClass(?string $mapperClass, array $mapperArgs = []): self
	{
		return new self(
			$this->gateway,
			$this->sourceRepresentation,
			$this->propertyRepresentation,
			$this->outputRepresentation,
			$mapperClass,
			$mapperArgs,
			$this->collection,
			$this->blueprint,
		);
	}

	public function asCollection(): self
	{
		return new self(
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
}
