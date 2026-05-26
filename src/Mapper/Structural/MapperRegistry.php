<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\ConversionGateway;
use RuntimeException;

final class MapperRegistry
{
	/** @var list<MapperInterface> */
	private array $mappers = [];

	public function __construct(MapperInterface ...$mappers)
	{
		foreach ($mappers as $mapper) {
			$this->register($mapper);
		}
	}

	public static function createDefault(ConversionGateway $gateway): self
	{
		return new self(
			new CollectionRowMapper($gateway),
			new PsrRequestToObjectMapper($gateway),
			new ArrayToObjectMapper($gateway),
			new ObjectToArrayMapper($gateway),
		);
	}

	public function register(MapperInterface $mapper): void
	{
		$this->mappers[] = $mapper;
	}

	public function replace(MapperInterface $mapper): void
	{
		$this->mappers = array_values(array_filter(
			$this->mappers,
			static fn (MapperInterface $existing): bool => $existing::class !== $mapper::class,
		));
		$this->register($mapper);
	}

	/**
	 * @return list<MapperInterface>
	 */
	public function all(): array
	{
		return $this->mappers;
	}

	public function map(mixed $from, mixed $to, MappingContext $context): mixed
	{
		if ($context->mapperClass !== null) {
			foreach ($this->mappers as $mapper) {
				if ($mapper::class === $context->mapperClass && $mapper->canMap($from, $to, $context)) {
					return $mapper->map($from, $to, $context);
				}
			}

			throw new RuntimeException(sprintf(
				'Mapper `%s` cannot map %s → %s.',
				$context->mapperClass,
				is_object($from) ? $from::class : get_debug_type($from),
				is_string($to) ? $to : get_debug_type($to),
			));
		}

		foreach ($this->mappers as $mapper) {
			if ($mapper->canMap($from, $to, $context)) {
				return $mapper->map($from, $to, $context);
			}
		}

		throw new RuntimeException(sprintf(
			'No mapper found for %s → %s.',
			is_object($from) ? $from::class : get_debug_type($from),
			is_string($to) ? $to : get_debug_type($to),
		));
	}
}
