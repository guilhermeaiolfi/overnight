<?php

declare(strict_types=1);

namespace Tests\ON\Mapper;

use InvalidArgumentException;
use ON\Data\Definition\Registry;
use ON\Mapper\ConversionGateway;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use function ON\Mapper\map;
use ON\Mapper\MapperConfig;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use PHPUnit\Framework\TestCase;

final class MapperConfigTest extends TestCase
{
	protected function tearDown(): void
	{
		ConversionGateway::reset();
	}

	public function testRegisteredFieldTypeIsAvailableViaMap(): void
	{
		ConversionGateway::configure(
			(new MapperConfig())->register('token', TokenFieldType::class)
		);

		$registry = new Registry();
		$registry->collection('session')
			->field('token', 'string')->type('token')->nullable(false)->end()
			->end();

		$collection = $registry->getCollection('session');

		$row = map(['token' => 'wire-value'])
			->using(CollectionRowMapper::class, $collection)
			->from(StorageRepresentation::class)
			->as(PhpRepresentation::class)
			->toArray();

		$this->assertInstanceOf(TokenValue::class, $row['token']);
		$this->assertSame('storage:wire-value', $row['token']->value);
	}

	public function testMapUsesConfiguredGatewayWithoutExplicitArgument(): void
	{
		ConversionGateway::configure(
			(new MapperConfig())->register('token', TokenFieldType::class)
		);

		$value = ConversionGateway::get()->to(
			StorageRepresentation::class,
			'abc',
			PhpRepresentation::class,
			FieldContext::named('token', 'token'),
		);

		$this->assertInstanceOf(TokenValue::class, $value);
		$this->assertSame('storage:abc', $value->value);
	}

	public function testEnumHandlerDoesNotReplaceBuiltinStringFieldType(): void
	{
		ConversionGateway::configure(
			(new MapperConfig())->register(PriorityEnum::class, PriorityEnumFieldType::class)
		);

		$token = ConversionGateway::get()->to(
			StorageRepresentation::class,
			'abc',
			PhpRepresentation::class,
			FieldContext::named('title', 'string'),
		);

		$priority = ConversionGateway::get()->to(
			StorageRepresentation::class,
			'high',
			PhpRepresentation::class,
			FieldContext::named('priority', PriorityEnum::class),
		);

		$this->assertSame('abc', $token);
		$this->assertSame(PriorityEnum::High, $priority);
	}

	public function testFieldTypeHandlerRequiresExplicitTypeKey(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('storageType()');

		(new MapperConfig())->register(TokenFieldType::class);
	}
}

final class TokenValue
{
	public function __construct(public readonly string $value)
	{
	}
}

final class TokenFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'string';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		return new TokenValue(match ($from) {
			StorageRepresentation::class => 'storage:' . (string) $value,
			WireRepresentation::class => 'wire:' . (string) $value,
			PhpRepresentation::class => $value,
			default => (string) $value,
		});
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		/** @var TokenValue $value */
		return match ($to) {
			StorageRepresentation::class => str_replace('storage:', '', $value->value),
			WireRepresentation::class => str_replace('wire:', '', $value->value),
			PhpRepresentation::class => $value,
			default => $value->value,
		};
	}
}

enum PriorityEnum: string
{
	case High = 'high';
	case Low = 'low';
}

final class PriorityEnumFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'string';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		/** @var class-string<PriorityEnum> $enum */
		$enum = $field->getType();

		return match ($from) {
			PhpRepresentation::class => $value,
			StorageRepresentation::class, WireRepresentation::class => $enum::from((string) $value),
			default => $value,
		};
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		/** @var PriorityEnum $value */
		return match ($to) {
			PhpRepresentation::class => $value,
			StorageRepresentation::class, WireRepresentation::class => $value->value,
			default => $value,
		};
	}
}
