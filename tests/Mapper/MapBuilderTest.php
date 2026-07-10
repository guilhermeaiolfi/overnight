<?php

declare(strict_types=1);

namespace Tests\ON\Mapper;

use DateTimeImmutable;
use InvalidArgumentException;
use ON\Data\Definition\Registry;
use ON\Mapper\Attribute\MapFrom;
use ON\Mapper\Attribute\MapTo;
use function ON\Mapper\map;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use PHPUnit\Framework\TestCase;

final class MapBuilderTest extends TestCase
{
	public function testMapsArrayToDtoWithoutContext(): void
	{
		$dto = map([
			'name' => 'Ada',
			'email' => 'ada@example.com',
		])->to(UserDto::class);

		$this->assertInstanceOf(UserDto::class, $dto);
		$this->assertSame('Ada', $dto->name);
		$this->assertSame('ada@example.com', $dto->email);
	}

	public function testMapsArrayToDtoUsingMapFromAttribute(): void
	{
		$dto = map([
			'full_name' => 'Grace',
		])->to(AliasedUserDto::class);

		$this->assertSame('Grace', $dto->name);
	}

	public function testMapsWirePayloadToDtoWithContextHint(): void
	{
		$dto = map([
			'created_at' => '2024-03-15T10:30:00+00:00',
		], WireRepresentation::class)->to(WireUserDto::class);

		$this->assertInstanceOf(DateTimeImmutable::class, $dto->createdAt);
		$this->assertSame('2024-03-15 10:30:00', $dto->createdAt->format('Y-m-d H:i:s'));
	}

	public function testMapsCollectionOfArraysToDtos(): void
	{
		$dtos = map([
			['name' => 'Ada'],
			['name' => 'Grace'],
		])->collection()->to(UserDto::class);

		$this->assertCount(2, $dtos);
		$this->assertContainsOnlyInstancesOf(UserDto::class, $dtos);
		$this->assertSame('Ada', $dtos[0]->name);
		$this->assertSame('Grace', $dtos[1]->name);
	}

	public function testMapsDtoToArrayWithoutContext(): void
	{
		$dto = new WireUserDto();
		$dto->createdAt = new DateTimeImmutable('2024-03-15T10:30:00+00:00');

		$array = map($dto)->toArray();

		$this->assertInstanceOf(DateTimeImmutable::class, $array['created_at']);
	}

	public function testMapsDtoToWireArrayWithContextHint(): void
	{
		$dto = new WireUserDto();
		$dto->createdAt = new DateTimeImmutable('2024-03-15T10:30:00+00:00');

		$array = map($dto)->as(WireRepresentation::class)->toArray();

		$this->assertSame('2024-03-15T10:30:00+00:00', $array['created_at']);
	}

	public function testMapsDtoToArrayUsingMapToAttribute(): void
	{
		$dto = new AliasedUserDto();
		$dto->name = 'Grace';

		$this->assertSame(['full_name' => 'Grace'], map($dto)->toArray());
	}

	public function testConvertsCollectionRowFromStorageToPhp(): void
	{
		$collection = $this->eventCollection();

		$row = map(['starts_at' => '2024-03-15 10:30:00'])
			->using(CollectionRowMapper::class, $collection)
			->from(StorageRepresentation::class)
			->as(PhpRepresentation::class)
			->toArray();

		$this->assertInstanceOf(DateTimeImmutable::class, $row['starts_at']);
	}

	public function testConvertsCollectionRowFromPhpToWire(): void
	{
		$collection = $this->eventCollection();

		$row = map(['starts_at' => new DateTimeImmutable('2024-03-15T10:30:00+00:00')])
			->using(CollectionRowMapper::class, $collection)
			->from(PhpRepresentation::class)
			->as(WireRepresentation::class)
			->toArray();

		$this->assertSame('2024-03-15T10:30:00+00:00', $row['starts_at']);
	}

	public function testConvertsCollectionRowsInBulk(): void
	{
		$collection = $this->eventCollection();

		$rows = map([
			['starts_at' => '2024-03-15 10:30:00'],
			['starts_at' => '2024-04-01 08:00:00'],
		])
			->using(CollectionRowMapper::class, $collection)
			->from(StorageRepresentation::class)
			->as(PhpRepresentation::class)
			->collection()
			->toArray();

		$this->assertCount(2, $rows);
		$this->assertInstanceOf(DateTimeImmutable::class, $rows[0]['starts_at']);
		$this->assertInstanceOf(DateTimeImmutable::class, $rows[1]['starts_at']);
	}

	public function testValueContextOverrideOnTo(): void
	{
		$dto = map([
			'created_at' => '2024-03-15T10:30:00+00:00',
		])->to(WireUserDto::class, WireRepresentation::class);

		$this->assertInstanceOf(DateTimeImmutable::class, $dto->createdAt);
	}

	public function testFromMethodOverridesSourceContext(): void
	{
		$collection = $this->eventCollection();

		$row = map(['starts_at' => '2024-03-15 10:30:00'])
			->using(CollectionRowMapper::class, $collection)
			->from(StorageRepresentation::class)
			->as(PhpRepresentation::class)
			->toArray();

		$this->assertInstanceOf(DateTimeImmutable::class, $row['starts_at']);
	}

	public function testToRejectsRepresentationHints(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Use as(');

		map(['starts_at' => '2024-03-15 10:30:00'])->to(PhpRepresentation::class);
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

final class UserDto
{
	public string $name = '';
	public string $email = '';
}

final class AliasedUserDto
{
	#[MapFrom('full_name')]
	#[MapTo('full_name')]
	public string $name = '';
}

final class WireUserDto
{
	#[MapFrom('created_at')]
	#[MapTo('created_at')]
	public DateTimeImmutable $createdAt;
}
