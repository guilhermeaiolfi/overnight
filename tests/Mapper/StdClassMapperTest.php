<?php

declare(strict_types=1);

namespace Tests\ON\Mapper;

use DateTimeImmutable;
use ON\Data\Definition\Registry;
use ON\Mapper\Attribute\MapField;
use ON\Mapper\Blueprint\MappingBlueprint;
use function ON\Mapper\map;
use ON\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\TestCase;
use stdClass;

final class StdClassMapperTest extends TestCase
{
	public function testMapsListOfObjectsToArrayOfStdClass(): void
	{
		$object = map([
			'name' => 'bar',
			'arr' => [
				[],
				[],
				[],
			],
		])->to(stdClass::class);

		$this->assertSame('bar', $object->name);
		$this->assertIsArray($object->arr);
		$this->assertCount(3, $object->arr);
		$this->assertContainsOnlyInstancesOf(stdClass::class, $object->arr);
	}

	public function testMapsAssociativeNestedArrayToStdClass(): void
	{
		$object = map([
			'author' => ['name' => 'Jane'],
		])->to(stdClass::class);

		$this->assertInstanceOf(stdClass::class, $object->author);
		$this->assertFalse(is_array($object->author));
		$this->assertSame('Jane', $object->author->name);
	}

	public function testMapsDotNotationToNestedStdClass(): void
	{
		$object = map([
			'author.name' => 'Jane',
			'items.0.id' => 'a',
			'items.1.id' => 'b',
		])->to(stdClass::class);

		$this->assertSame('Jane', $object->author->name);
		$this->assertIsArray($object->items);
		$this->assertCount(2, $object->items);
		$this->assertSame('a', $object->items[0]->id);
		$this->assertSame('b', $object->items[1]->id);
	}

	public function testMapsStdClassBackToArrayPreservingListShape(): void
	{
		$source = map([
			'name' => 'bar',
			'arr' => [[], []],
		])->to(stdClass::class);

		$array = map($source)->toArray();

		$this->assertSame('bar', $array['name']);
		$this->assertIsArray($array['arr']);
		$this->assertCount(2, $array['arr']);
		$this->assertIsArray($array['arr'][0]);
		$this->assertSame([], $array['arr'][0]);
	}

	public function testMapsWireDatetimeInboundWithBlueprint(): void
	{
		$blueprint = MappingBlueprint::fromArray([
			'meta' => [
				'created_at' => 'datetime',
			],
		]);

		$object = map([
			'meta' => ['created_at' => '2024-03-15T10:30:00+00:00'],
		], WireRepresentation::class)
			->args($blueprint)
			->to(stdClass::class);

		$this->assertInstanceOf(stdClass::class, $object->meta);
		$this->assertInstanceOf(DateTimeImmutable::class, $object->meta->created_at);
	}

	public function testMapsWireDatetimeOutboundWithBlueprint(): void
	{
		$blueprint = MappingBlueprint::fromArray([
			'meta' => [
				'created_at' => 'datetime',
			],
		]);

		$object = new stdClass();
		$object->meta = new stdClass();
		$object->meta->created_at = new DateTimeImmutable('2024-03-15T10:30:00+00:00');

		$array = map($object)
			->args($blueprint)
			->as(WireRepresentation::class)
			->toArray();

		$this->assertSame('2024-03-15T10:30:00+00:00', $array['meta']['created_at']);
	}

	public function testDoesNotConvertScalarsWithoutBlueprint(): void
	{
		$object = map([
			'meta' => ['created_at' => '2024-03-15T10:30:00+00:00'],
		], WireRepresentation::class)->to(stdClass::class);

		$this->assertIsString($object->meta->created_at);
	}

	public function testMapsNestedListToDtoWithBlueprint(): void
	{
		$blueprint = MappingBlueprint::fromArray([
			'children' => StdClassNestedChildDto::class,
		]);

		$object = map([
			'children' => [
				['name' => 'Ada'],
				['name' => 'Grace'],
			],
		])
			->args($blueprint)
			->to(stdClass::class);

		$this->assertIsArray($object->children);
		$this->assertContainsOnlyInstancesOf(StdClassNestedChildDto::class, $object->children);
	}

	public function testBuildsBlueprintFromShapeClass(): void
	{
		$blueprint = MappingBlueprint::fromClass(StdClassWireShape::class);

		$object = map([
			'meta' => ['created_at' => '2024-03-15T10:30:00+00:00'],
		], WireRepresentation::class)
			->args($blueprint)
			->to(stdClass::class);

		$this->assertInstanceOf(DateTimeImmutable::class, $object->meta->created_at);
	}

	public function testRoundTripsNestedStdClassGraph(): void
	{
		$source = map([
			'title' => 'Book',
			'chapters' => [
				['name' => 'One'],
				['name' => 'Two'],
			],
		])->to(stdClass::class);

		$array = map($source)->toArray();

		$this->assertIsArray($array['chapters']);
		$this->assertSame('One', $array['chapters'][0]['name']);
		$this->assertSame('Two', $array['chapters'][1]['name']);
	}

	public function testCollectionBlueprintMapsListRelationScalars(): void
	{
		$registry = new Registry();
		$registry->collection('post')
			->field('published_at', 'datetime')->end()
			->hasMany('comments', 'comment')->innerKey('id')->outerKey('post_id')->end()
			->end();
		$registry->collection('comment')
			->field('posted_at', 'datetime')->end()
			->end();

		$blueprint = MappingBlueprint::fromCollection($registry->getCollection('post'), 1);

		$object = map([
			'published_at' => '2024-03-15T10:30:00+00:00',
			'comments' => [
				['posted_at' => '2024-03-16T11:30:00+00:00'],
			],
		], WireRepresentation::class)
			->args($blueprint)
			->to(stdClass::class);

		$this->assertInstanceOf(DateTimeImmutable::class, $object->published_at);
		$this->assertInstanceOf(DateTimeImmutable::class, $object->comments[0]->posted_at);
	}
}

final class StdClassNestedChildDto
{
	public string $name = '';
}

final class StdClassWireShape
{
	public StdClassWireMetaShape $meta;
}

final class StdClassWireMetaShape
{
	#[MapField('datetime')]
	public string $created_at = '';
}
