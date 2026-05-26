<?php

declare(strict_types=1);

namespace Tests\ON\Mapper;

use ON\Mapper\ConversionGateway;
use ON\Mapper\Exception\ConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\ORM\Definition\Registry;
use PHPUnit\Framework\TestCase;

use function ON\Mapper\map;

final class ConversionGatewayTest extends TestCase
{
	private ConversionGateway $gateway;

	protected function setUp(): void
	{
		$this->gateway = ConversionGateway::createDefault();
	}

	public function testSameRepresentationReturnsValueUnchanged(): void
	{
		$field = FieldContext::named('title', 'string');

		$this->assertSame('hello', $this->gateway->to(PhpRepresentation::class, 'hello', PhpRepresentation::class, $field));
	}

	public function testNullReturnsNull(): void
	{
		$field = FieldContext::named('title', 'string', true);

		$this->assertNull($this->gateway->to(StorageRepresentation::class, null, PhpRepresentation::class, $field));
	}

	public function testStorageToPhpDatetime(): void
	{
		$registry = new Registry();
		$registry->collection('event')
			->field('starts_at', 'datetime')->type('datetime')->nullable(true)->end()
			->end();

		$field = FieldContext::fromField($registry->getCollection('event')->fields->get('starts_at'));

		$value = $this->gateway->to(
			StorageRepresentation::class,
			'2024-03-15 10:30:00',
			PhpRepresentation::class,
			$field,
		);

		$this->assertInstanceOf(\DateTimeImmutable::class, $value);
	}

	public function testPhpToWireDatetime(): void
	{
		$field = FieldContext::named('starts_at', 'datetime');

		$value = $this->gateway->to(
			PhpRepresentation::class,
			new \DateTimeImmutable('2024-03-15T10:30:00+00:00'),
			WireRepresentation::class,
			$field,
		);

		$this->assertSame('2024-03-15T10:30:00+00:00', $value);
	}

	public function testFieldMappingFluentEntrypoint(): void
	{
		$field = FieldContext::named('starts_at', 'datetime');

		$value = $this->gateway->map($field)->to(
			StorageRepresentation::class,
			'2024-03-15 10:30:00',
			PhpRepresentation::class,
		);

		$this->assertInstanceOf(\DateTimeImmutable::class, $value);
	}
}

final class CollectionRowMapperTest extends TestCase
{
	public function testFromPhpDatetimeToStorageFormat(): void
	{
		$collection = $this->eventCollection();

		$result = map([
			'starts_at' => new \DateTimeImmutable('2024-03-15T10:30:00+00:00'),
		])
			->using(CollectionRowMapper::class, $collection)
			->from(PhpRepresentation::class)
			->as(StorageRepresentation::class)
			->toArray();

		$this->assertSame('2024-03-15 10:30:00', $result['starts_at']);
	}

	public function testToPhpDatetimeFromStorageFormat(): void
	{
		$collection = $this->eventCollection();

		$result = map([
			'starts_at' => '2024-03-15 10:30:00',
		])
			->using(CollectionRowMapper::class, $collection)
			->from(StorageRepresentation::class)
			->as(PhpRepresentation::class)
			->toArray();

		$this->assertInstanceOf(\DateTimeImmutable::class, $result['starts_at']);
		$this->assertSame(
			'2024-03-15 10:30:00',
			$result['starts_at']->format('Y-m-d H:i:s')
		);
	}

	public function testFromPhpDateToStorageFormat(): void
	{
		$registry = new Registry();
		$registry->collection('report')
			->field('reference_date', 'date')->type('date')->nullable(false)->end()
			->end();

		$collection = $registry->getCollection('report');

		$result = map([
			'reference_date' => new \DateTimeImmutable('2024-03-15T00:00:00Z'),
		])
			->using(CollectionRowMapper::class, $collection)
			->from(PhpRepresentation::class)
			->as(StorageRepresentation::class)
			->toArray();

		$this->assertSame('2024-03-15', $result['reference_date']);
	}

	public function testFromPhpNullableStringEmptyToNull(): void
	{
		$registry = new Registry();
		$registry->collection('post')
			->field('intro', 'string')->type('string')->nullable(true)->end()
			->end();

		$collection = $registry->getCollection('post');

		$result = map([
			'intro' => '   ',
		])
			->using(CollectionRowMapper::class, $collection)
			->from(PhpRepresentation::class)
			->as(StorageRepresentation::class)
			->toArray();

		$this->assertNull($result['intro']);
	}

	public function testOnlyTouchesProvidedFields(): void
	{
		$registry = new Registry();
		$registry->collection('post')
			->field('title', 'string')->type('string')->nullable(true)->end()
			->field('intro', 'string')->type('string')->nullable(true)->end()
			->end();

		$collection = $registry->getCollection('post');

		$result = map([
			'intro' => '',
		])
			->using(CollectionRowMapper::class, $collection)
			->from(PhpRepresentation::class)
			->as(StorageRepresentation::class)
			->toArray();

		$this->assertSame(['intro' => null], $result);
	}

	public function testFromPhpBoolFromString(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->field('active', 'bool')->type('bool')->nullable(true)->end()
			->end();

		$collection = $registry->getCollection('user');

		$result = map([
			'active' => 'true',
		])
			->using(CollectionRowMapper::class, $collection)
			->from(PhpRepresentation::class)
			->as(StorageRepresentation::class)
			->toArray();

		$this->assertTrue($result['active']);
	}

	public function testInvalidDatetimeThrowsConversionException(): void
	{
		$collection = $this->eventCollection();

		$this->expectException(ConversionException::class);

		map([
			'starts_at' => 'not-a-date',
		])
			->using(CollectionRowMapper::class, $collection)
			->from(PhpRepresentation::class)
			->as(StorageRepresentation::class)
			->toArray();
	}

	public function testSameRepresentationReturnsRowUnchanged(): void
	{
		$collection = $this->eventCollection();
		$row = ['starts_at' => '2024-03-15 10:30:00', 'extra' => 'kept'];

		$result = map($row)
			->using(CollectionRowMapper::class, $collection)
			->from(StorageRepresentation::class)
			->as(StorageRepresentation::class)
			->toArray();

		$this->assertSame($row, $result);
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
