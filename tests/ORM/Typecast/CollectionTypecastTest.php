<?php

declare(strict_types=1);

namespace Tests\ON\ORM\Typecast;

use ON\ORM\Definition\Registry;
use ON\ORM\Typecast\CollectionTypecast;
use ON\ORM\Typecast\TypecastException;
use PHPUnit\Framework\TestCase;

final class CollectionTypecastTest extends TestCase
{
	private CollectionTypecast $typecast;

	protected function setUp(): void
	{
		$this->typecast = new CollectionTypecast();
	}

	public function testUncastDatetimeFromIsoString(): void
	{
		$registry = new Registry();
		$registry->collection('event')
			->field('starts_at', 'datetime')->type('datetime')->nullable(true)->end()
			->end();

		$collection = $registry->getCollection('event');

		$result = $this->typecast->uncast($collection, [
			'starts_at' => '2024-03-15T10:30:00+00:00',
		]);

		$this->assertSame('2024-03-15 10:30:00', $result['starts_at']);
	}

	public function testCastDatetimeToIsoString(): void
	{
		$registry = new Registry();
		$registry->collection('event')
			->field('starts_at', 'datetime')->type('datetime')->nullable(true)->end()
			->end();

		$collection = $registry->getCollection('event');

		$result = $this->typecast->cast($collection, [
			'starts_at' => '2024-03-15 10:30:00',
		]);

		$this->assertSame(
			(new \DateTimeImmutable('2024-03-15 10:30:00'))->format(\DateTimeInterface::ATOM),
			$result['starts_at']
		);
	}

	public function testUncastDateToStorageFormat(): void
	{
		$registry = new Registry();
		$registry->collection('report')
			->field('reference_date', 'date')->type('date')->nullable(false)->end()
			->end();

		$collection = $registry->getCollection('report');

		$result = $this->typecast->uncast($collection, [
			'reference_date' => '2024-03-15T00:00:00Z',
		]);

		$this->assertSame('2024-03-15', $result['reference_date']);
	}

	public function testUncastNullableStringEmptyToNull(): void
	{
		$registry = new Registry();
		$registry->collection('post')
			->field('intro', 'string')->type('string')->nullable(true)->end()
			->end();

		$collection = $registry->getCollection('post');

		$result = $this->typecast->uncast($collection, [
			'intro' => '   ',
		]);

		$this->assertNull($result['intro']);
	}

	public function testPartialUncastOnlyTouchesProvidedFields(): void
	{
		$registry = new Registry();
		$registry->collection('post')
			->field('title', 'string')->type('string')->nullable(true)->end()
			->field('intro', 'string')->type('string')->nullable(true)->end()
			->end();

		$collection = $registry->getCollection('post');

		$result = $this->typecast->uncast($collection, [
			'intro' => '',
		], partial: true);

		$this->assertSame(['intro' => null], $result);
	}

	public function testUncastBoolFromString(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->field('active', 'bool')->type('bool')->nullable(true)->end()
			->end();

		$collection = $registry->getCollection('user');

		$result = $this->typecast->uncast($collection, [
			'active' => 'true',
		]);

		$this->assertTrue($result['active']);
	}

	public function testInvalidDatetimeThrowsTypecastException(): void
	{
		$registry = new Registry();
		$registry->collection('event')
			->field('starts_at', 'datetime')->type('datetime')->nullable(true)->end()
			->end();

		$collection = $registry->getCollection('event');

		$this->expectException(TypecastException::class);
		$this->typecast->uncast($collection, [
			'starts_at' => 'not-a-date',
		]);
	}
}
