<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\ConversionGateway;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class MapperRegistry
{
	/** @var list<class-string<MapperInterface>> */
	private array $mappers = [];

	/** @var array<class-string<MapperInterface>, MapperInterface> */
	private array $instances = [];

	public function __construct(
		private readonly ?ContainerInterface $container = null,
		private readonly ?ConversionGateway $gateway = null,
	) {
	}

	public static function createDefault(ConversionGateway $gateway, ?ContainerInterface $container = null): self
	{
		$registry = new self($container, $gateway);
		$registry->register(CollectionRowMapper::class);
		$registry->register(PsrRequestToObjectMapper::class);
		$registry->register(ArrayToStdClassMapper::class);
		$registry->register(ArrayToObjectMapper::class);
		$registry->register(StdClassToArrayMapper::class);
		$registry->register(ObjectToArrayMapper::class);

		return $registry;
	}

	/** @param class-string<MapperInterface> $mapperClass */
	public function register(string $mapperClass): void
	{
		if (in_array($mapperClass, $this->mappers, true)) {
			throw new RuntimeException("Mapper `{$mapperClass}` is already registered.");
		}

		$this->mappers[] = $mapperClass;
	}

	/** @param class-string<MapperInterface> $mapperClass */
	public function replace(string $mapperClass): void
	{
		$this->mappers = array_values(array_filter(
			$this->mappers,
			static fn (string $existing): bool => $existing !== $mapperClass,
		));
		unset($this->instances[$mapperClass]);
		$this->mappers[] = $mapperClass;
	}

	/**
	 * @return list<class-string<MapperInterface>>
	 */
	public function classes(): array
	{
		return $this->mappers;
	}

	public function map(mixed $from, mixed $to, MappingContext $context): mixed
	{
		if ($context->mapperClass !== null) {
			if (in_array($context->mapperClass, $this->mappers, true)
				&& $context->mapperClass::canMap($from, $to, $context)) {
				return $this->resolve($context->mapperClass)->map($from, $to, $context);
			}

			throw new RuntimeException(sprintf(
				'Mapper `%s` cannot map %s → %s.',
				$context->mapperClass,
				is_object($from) ? $from::class : get_debug_type($from),
				is_string($to) ? $to : get_debug_type($to),
			));
		}

		foreach ($this->mappers as $mapperClass) {
			if ($mapperClass::canMap($from, $to, $context)) {
				return $this->resolve($mapperClass)->map($from, $to, $context);
			}
		}

		throw new RuntimeException(sprintf(
			'No mapper found for %s → %s.',
			is_object($from) ? $from::class : get_debug_type($from),
			is_string($to) ? $to : get_debug_type($to),
		));
	}

	/** @param class-string<MapperInterface> $mapperClass */
	private function resolve(string $mapperClass): MapperInterface
	{
		if (isset($this->instances[$mapperClass])) {
			return $this->instances[$mapperClass];
		}

		if ($this->container?->has($mapperClass)) {
			$mapper = $this->container->get($mapperClass);
			if (! $mapper instanceof MapperInterface) {
				throw new RuntimeException("Container entry `{$mapperClass}` is not a mapper.");
			}

			return $this->instances[$mapperClass] = $mapper;
		}

		if ($this->gateway !== null) {
			return $this->instances[$mapperClass] = new $mapperClass($this->gateway);
		}

		return $this->instances[$mapperClass] = new $mapperClass();
	}
}
