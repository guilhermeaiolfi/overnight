<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\ConversionGateway;
use ON\Mapper\Representation\RepresentationInterface;

/**
 * Configuration for one map() run, shared by the whole structural walk.
 *
 * Built from MapBuilder (from/as, using, args, resolver). Holds representation hints,
 * mapper choice, and mapper-specific args (collection, blueprint, etc.). Passed unchanged
 * into nested mapping; pathPrefix grows as walkers recurse.
 */
final class MappingContext
{
	public function __construct(
		public readonly ConversionGateway $gateway,
		/** @var class-string<RepresentationInterface>|null */
		public readonly ?string $sourceRepresentation = null,
		/** @var class-string<RepresentationInterface>|null */
		public readonly ?string $outputRepresentation = null,
		public readonly ?string $mapperClass = null,
		public readonly array $args = [],
		public readonly bool $collection = false,
		public readonly string $pathPrefix = '',
		/** @var class-string|null */
		public readonly ?string $resolverClass = null,
		public readonly array $resolverArgs = [],
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
			$this->outputRepresentation,
			$this->mapperClass,
			$this->args,
			$this->collection,
			$this->pathPrefix,
			$this->resolverClass,
			$this->resolverArgs,
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
			$representation,
			$this->mapperClass,
			$this->args,
			$this->collection,
			$this->pathPrefix,
			$this->resolverClass,
			$this->resolverArgs,
		);
	}

	public function withMapperClass(?string $mapperClass, array $args = []): self
	{
		return new self(
			$this->gateway,
			$this->sourceRepresentation,
			$this->outputRepresentation,
			$mapperClass,
			$args !== [] ? $args : $this->args,
			$this->collection,
			$this->pathPrefix,
			$this->resolverClass,
			$this->resolverArgs,
		);
	}

	public function withPathSegment(string $key): self
	{
		$path = $this->pathPrefix === '' ? $key : $this->pathPrefix . '.' . $key;

		return new self(
			$this->gateway,
			$this->sourceRepresentation,
			$this->outputRepresentation,
			$this->mapperClass,
			$this->args,
			$this->collection,
			$path,
			$this->resolverClass,
			$this->resolverArgs,
		);
	}

	public function withNodes(?object $current, ?object $parent): self
	{
		return $this;
	}

	public function asCollection(): self
	{
		return new self(
			$this->gateway,
			$this->sourceRepresentation,
			$this->outputRepresentation,
			$this->mapperClass,
			$this->args,
			true,
			$this->pathPrefix,
			$this->resolverClass,
			$this->resolverArgs,
		);
	}
}
