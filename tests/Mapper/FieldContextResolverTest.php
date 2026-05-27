<?php

declare(strict_types=1);

namespace Tests\ON\Mapper;

use DateTimeImmutable;
use ON\Mapper\Attribute\MapFrom;
use ON\Mapper\Conversion\ConversionDirection;
use ON\Mapper\Conversion\ScalarFieldResolverOverrideInterface;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Structural\MappingContext;
use PHPUnit\Framework\TestCase;

use function ON\Mapper\map;

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
}

final class ResolverWireUserDto
{
	#[MapFrom('created_at')]
	public DateTimeImmutable $createdAt;
	public string $name = '';
}

final class CreatedAtOnlyResolver implements ScalarFieldResolverOverrideInterface
{
	public function resolve(
		MappingContext $mapping,
		string $path,
		string $fieldName,
		mixed $value,
		ConversionDirection $direction,
	): ?FieldContext {
		if ($fieldName !== 'created_at') {
			return null;
		}

		return FieldContext::named('created_at', 'datetime', false);
	}
}
