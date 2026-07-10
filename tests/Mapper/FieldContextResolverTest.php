<?php

declare(strict_types=1);

namespace Tests\ON\Mapper;

use DateTimeImmutable;
use ON\Data\Definition\Registry;
use ON\Mapper\Attribute\MapFrom;
use ON\Mapper\Conversion\ConversionDirection;
use ON\Mapper\Conversion\FieldResolverInterface;
use ON\Mapper\Field\FieldContext;
use function ON\Mapper\map;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\Mapper\Structural\MappingContext;
use PHPUnit\Framework\TestCase;

final class FieldContextResolverTest extends TestCase
{
	public function testCustomResolverHandlesOnlyMatchingField(): void
	{
		$dto = map([
			'created_at' => '2024-03-15T10:30:00+00:00',
			'name' => 'Ada',
		], WireRepresentation::class)
			->resolver(CreatedAtOnlyResolver::class)
			->to(ResolverWireUserDto::class);

		$this->assertInstanceOf(DateTimeImmutable::class, $dto->createdAt);
		$this->assertSame('Ada', $dto->name);
	}

	public function testResolverCallsStackInOrder(): void
	{
		$dto = map([
			'starts_at' => '2024-03-15T10:30:00+00:00',
		], WireRepresentation::class)
			->resolver(NullFieldResolver::class)
			->resolver(UntypedStartsAtResolver::class)
			->to(ResolverUntypedEventDto::class);

		$this->assertInstanceOf(DateTimeImmutable::class, $dto->startsAt);
	}

	public function testMapperDefaultResolverRunsBeforeConfiguredResolvers(): void
	{
		$collection = $this->eventCollection();

		$row = map(['starts_at' => '2024-03-15 10:30:00'])
			->using(CollectionRowMapper::class, $collection)
			->resolver(StringStartsAtResolver::class)
			->from(StorageRepresentation::class)
			->as(PhpRepresentation::class)
			->toArray();

		$this->assertInstanceOf(DateTimeImmutable::class, $row['starts_at']);
	}

	private function eventCollection()
	{
		$registry = new Registry();
		$registry->collection('event')
			->field('starts_at', 'datetime')->type('datetime')->nullable(true)->end()
			->end();

		return $registry->getCollection('event');
	}
}

final class ResolverWireUserDto
{
	#[MapFrom('created_at')]
	public DateTimeImmutable $createdAt;
	public string $name = '';
}

final class ResolverUntypedEventDto
{
	#[MapFrom('starts_at')]
	public $startsAt;
}

final class CreatedAtOnlyResolver implements FieldResolverInterface
{
	public function resolve(
		MappingContext $mapping,
		string $path,
		string $fieldName,
		mixed $value,
		ConversionDirection $direction,
		mixed $extra = null,
	): ?FieldContext {
		if ($fieldName !== 'created_at') {
			return null;
		}

		return FieldContext::named('created_at', 'datetime', false);
	}
}

final class NullFieldResolver implements FieldResolverInterface
{
	public function resolve(
		MappingContext $mapping,
		string $path,
		string $fieldName,
		mixed $value,
		ConversionDirection $direction,
		mixed $extra = null,
	): ?FieldContext {
		return null;
	}
}

final class StringStartsAtResolver implements FieldResolverInterface
{
	public function resolve(
		MappingContext $mapping,
		string $path,
		string $fieldName,
		mixed $value,
		ConversionDirection $direction,
		mixed $extra = null,
	): ?FieldContext {
		if ($fieldName !== 'starts_at') {
			return null;
		}

		return FieldContext::named('starts_at', 'text', false);
	}
}

final class UntypedStartsAtResolver implements FieldResolverInterface
{
	public function resolve(
		MappingContext $mapping,
		string $path,
		string $fieldName,
		mixed $value,
		ConversionDirection $direction,
		mixed $extra = null,
	): ?FieldContext {
		if ($fieldName !== 'startsAt') {
			return null;
		}

		return FieldContext::named('startsAt', 'datetime', false);
	}
}
