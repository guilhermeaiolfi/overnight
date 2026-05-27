<?php



declare(strict_types=1);



namespace ON\Mapper;



use ON\Mapper\Conversion\ScalarFieldResolverOverrideInterface;

use ON\Mapper\Representation\RepresentationResolver;

use ON\Mapper\Structural\MapperInterface;

use ON\Mapper\Structural\MappingContext;

use InvalidArgumentException;



/**

 * Fluent entry for map(): representations, mapper args, optional resolver, structural target.

 *

 * Produces MappingContext and delegates shape mapping to MapperRegistry. Scalar conversion

 * happens inside structural mappers via FieldConversionCoordinator, not on this class.

 */

final class MapBuilder

{

	public function __construct(

		private readonly mixed $source,

		private readonly ConversionGateway $gateway,

		/** @var class-string|null */

		private readonly ?string $sourceRepresentation = null,

		/** @var class-string|null */

		private readonly ?string $outputRepresentation = null,

		private readonly ?string $mapperClass = null,

		private readonly array $args = [],

		private readonly bool $asCollection = false,

		/** @var class-string|null */

		private readonly ?string $resolverClass = null,

		private readonly array $resolverArgs = [],

	) {

	}



	/**

	 * @param class-string|null $representation

	 */

	public function from(?string $representation): self

	{

		return $this->clone(

			sourceRepresentation: RepresentationResolver::resolve($representation) ?? $this->sourceRepresentation,

		);

	}



	/**

	 * Target value encoding after field conversion.

	 *

	 * @param class-string|null $representation

	 */

	public function as(?string $representation): self

	{

		return $this->clone(

			outputRepresentation: RepresentationResolver::resolve($representation) ?? $this->outputRepresentation,

		);

	}



	/**

	 * @param class-string<MapperInterface> $mapperClass

	 */

	public function using(string $mapperClass, mixed ...$args): self

	{

		return $this->clone(

			mapperClass: $mapperClass,

			args: $args,

		);

	}



	public function args(mixed ...$args): self

	{

		return $this->clone(args: $args);

	}



	/**

	 * @param class-string<ScalarFieldResolverOverrideInterface> $resolverClass

	 */

	public function resolver(string $resolverClass, mixed ...$args): self

	{

		return $this->clone(

			resolverClass: $resolverClass,

			resolverArgs: $args,

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



		return $this->gateway->getMappers()->map($this->source, $target, $context);

	}



	public function collection(): self

	{

		return $this->clone(asCollection: true);

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

		return $this->gateway->getMappers()->map($this->source, 'array', $context);

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

			$this->outputRepresentation,

			$this->mapperClass,

			$this->args,

			resolverClass: $this->resolverClass,

			resolverArgs: $this->resolverArgs,

		);



		return $this->applyMapperDefaults($context);

	}



	private function applyMapperDefaults(MappingContext $context): MappingContext

	{

		if ($context->mapperClass === null) {

			return $context;

		}



		foreach ($this->gateway->getMappers()->all() as $mapper) {

			if ($mapper::class !== $context->mapperClass) {

				continue;

			}



			$defaults = $mapper->defaultRepresentations();

			$targetRepresentation = $defaults['as'] ?? $defaults['to'] ?? null;



			return new MappingContext(

				$context->gateway,

				$context->sourceRepresentation ?? $defaults['from'] ?? null,

				$context->outputRepresentation ?? $targetRepresentation,

				$context->mapperClass,

				$context->args,

				$context->collection,

				$context->pathPrefix,

				$context->resolverClass,

				$context->resolverArgs,

			);

		}



		return $context;

	}



	/**

	 * @param class-string|null $sourceRepresentation

	 * @param class-string|null $outputRepresentation

	 * @param class-string|null $mapperClass

	 * @param class-string|null $resolverClass

	 */

	private function clone(

		?string $sourceRepresentation = null,

		?string $outputRepresentation = null,

		?string $mapperClass = null,

		?array $args = null,

		?bool $asCollection = null,

		?string $resolverClass = null,

		?array $resolverArgs = null,

	): self {

		return new self(

			$this->source,

			$this->gateway,

			$sourceRepresentation ?? $this->sourceRepresentation,

			$outputRepresentation ?? $this->outputRepresentation,

			$mapperClass ?? $this->mapperClass,

			$args ?? $this->args,

			$asCollection ?? $this->asCollection,

			$resolverClass ?? $this->resolverClass,

			$resolverArgs ?? $this->resolverArgs,

		);

	}

}

