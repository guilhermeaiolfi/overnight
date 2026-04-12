<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL;

use ON\ORM\Definition\Metadata\MetadataMap;
use PHPUnit\Framework\TestCase;

final class MetadataMapTest extends TestCase
{
	public function testCanBeCreated(): void
	{
		$map = new MetadataMap();
		$this->assertInstanceOf(MetadataMap::class, $map);
	}

	public function testSetAndGet(): void
	{
		$map = new MetadataMap();
		$map->set('key', 'value');

		$this->assertSame('value', $map->get('key'));
	}

	public function testGetWithDefault(): void
	{
		$map = new MetadataMap();

		$this->assertSame('default', $map->get('missing', 'default'));
		$this->assertNull($map->get('missing'));
	}

	public function testHas(): void
	{
		$map = new MetadataMap();
		$map->set('key', 'value');

		$this->assertTrue($map->has('key'));
		$this->assertFalse($map->has('missing'));
	}

	public function testRemove(): void
	{
		$map = new MetadataMap();
		$map->set('key', 'value');
		$map->remove('key');

		$this->assertFalse($map->has('key'));
	}

	public function testAll(): void
	{
		$map = new MetadataMap();
		$map->set('key1', 'value1');
		$map->set('key2', 'value2');

		$this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $map->all());
	}

	public function testCanStoreMixedValues(): void
	{
		$map = new MetadataMap();
		$map->set('string', 'value');
		$map->set('int', 42);
		$map->set('array', [1, 2, 3]);
		$map->set('callable', fn () => 'result');
		$map->set('null', null);

		$this->assertSame('value', $map->get('string'));
		$this->assertSame(42, $map->get('int'));
		$this->assertSame([1, 2, 3], $map->get('array'));
		$this->assertTrue(is_callable($map->get('callable')));
		$this->assertNull($map->get('null'));
	}

	public function testIsIterable(): void
	{
		$map = new MetadataMap();
		$map->set('key1', 'value1');
		$map->set('key2', 'value2');

		$iterated = [];
		foreach ($map as $key => $value) {
			$iterated[$key] = $value;
		}

		$this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $iterated);
	}
}