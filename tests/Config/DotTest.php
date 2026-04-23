<?php

declare(strict_types=1);

namespace Tests\ON\Config;

use ON\Config\Dot;
use PHPUnit\Framework\TestCase;

final class DotTest extends TestCase
{
	public function testCanBeCreatedWithEmptyArray(): void
	{
		$dot = new Dot();
		$this->assertInstanceOf(Dot::class, $dot);
	}

	public function testCanBeCreatedWithArray(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$this->assertSame(['foo' => 'bar'], $dot->all());
	}

	public function testCanBeCreatedWithObject(): void
	{
		$dot = new Dot((object) ['foo' => 'bar']);
		$this->assertSame(['foo' => 'bar'], $dot->all());
	}

	public function testSetAddsValue(): void
	{
		$dot = new Dot();
		$result = $dot->set('foo', 'bar');
		$this->assertSame('bar', $dot->get('foo'));
		$this->assertInstanceOf(Dot::class, $result);
	}

	public function testGetReturnsDefaultForMissingKey(): void
	{
		$dot = new Dot();
		$this->assertNull($dot->get('missing'));
		$this->assertSame('default', $dot->get('missing', 'default'));
	}

	public function testGetReturnsAllItemsWhenKeyIsNull(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$this->assertSame(['foo' => 'bar'], $dot->get(null));
	}

	public function testHasReturnsTrueForExistingKey(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$this->assertTrue($dot->has('foo'));
	}

	public function testHasReturnsFalseForMissingKey(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$this->assertFalse($dot->has('missing'));
	}

	public function testHasSupportsNestedKeys(): void
	{
		$dot = new Dot(['foo' => ['bar' => 'baz']]);
		$this->assertTrue($dot->has('foo.bar'));
		$this->assertFalse($dot->has('foo.qux'));
	}

	public function testDeleteRemovesKey(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$dot->delete('foo');
		$this->assertFalse($dot->has('foo'));
	}

	public function testDeleteSupportsMultipleKeys(): void
	{
		$dot = new Dot(['foo' => 'bar', 'baz' => 'qux']);
		$dot->delete(['foo', 'baz']);
		$this->assertFalse($dot->has('foo'));
		$this->assertFalse($dot->has('baz'));
	}

	public function testClearRemovesAllItems(): void
	{
		$dot = new Dot(['foo' => 'bar', 'baz' => 'qux']);
		$dot->clear();
		$this->assertTrue($dot->isEmpty());
	}

	public function testMergeCombinesArrays(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$dot->merge(['baz' => 'qux']);
		$this->assertSame(['foo' => 'bar', 'baz' => 'qux'], $dot->all());
	}

	public function testMergeWithKeyMergesNestedData(): void
	{
		$dot = new Dot(['items' => ['a', 'b']]);
		$dot->merge('items', ['c', 'd']);
		$this->assertSame(['items' => ['a', 'b', 'c', 'd']], $dot->all());
	}

	public function testPullReturnsAndRemovesValue(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$value = $dot->pull('foo');
		$this->assertSame('bar', $value);
		$this->assertFalse($dot->has('foo'));
	}

	public function testPullAllReturnsAllAndClears(): void
	{
		$dot = new Dot(['foo' => 'bar', 'baz' => 'qux']);
		$value = $dot->pull();
		$this->assertSame(['foo' => 'bar', 'baz' => 'qux'], $value);
		$this->assertTrue($dot->isEmpty());
	}

	public function testPushAddsValueToArray(): void
	{
		$dot = new Dot(['items' => []]);
		$dot->push('items', 'new');
		$this->assertSame(['items' => ['new']], $dot->all());
	}

	public function testPushWithoutValueAddsToEndOfArray(): void
	{
		$dot = new Dot();
		$dot->push('items', 'first');
		$dot->push('items', 'second');
		$this->assertSame(['items' => ['first', 'second']], $dot->all());
	}

	public function testSetNestedKeysWithDotNotation(): void
	{
		$dot = new Dot();
		$dot->set('foo.bar.baz', 'value');
		$this->assertSame(['foo' => ['bar' => ['baz' => 'value']]], $dot->all());
	}

	public function testGetNestedKeysWithDotNotation(): void
	{
		$dot = new Dot(['foo' => ['bar' => ['baz' => 'value']]]);
		$this->assertSame('value', $dot->get('foo.bar.baz'));
	}

	public function testIsEmptyReturnsTrueForEmptyDot(): void
	{
		$dot = new Dot();
		$this->assertTrue($dot->isEmpty());
	}

	public function testIsEmptyReturnsFalseForNonEmptyDot(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$this->assertFalse($dot->isEmpty());
	}

	public function testReplaceOverwritesExistingData(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$dot->replace(['foo' => 'baz']);
		$this->assertSame(['foo' => 'baz'], $dot->all());
	}

	public function testFlattenConvertsNestedArrayToFlat(): void
	{
		$dot = new Dot(['a' => ['b' => 'c', 'd' => 'e']]);
		$result = $dot->flatten('.');
		$this->assertSame(['a.b' => 'c', 'a.d' => 'e'], $result);
	}

	public function testToJsonEncodesAllData(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$result = $dot->toJson();
		$this->assertSame('{"foo":"bar"}', $result);
	}

	public function testToJsonEncodesSpecificKey(): void
	{
		$dot = new Dot(['foo' => 'bar', 'baz' => 'qux']);
		$result = $dot->toJson('foo');
		$this->assertSame('"bar"', $result);
	}

	public function testSetArrayReplacesAllData(): void
	{
		$dot = new Dot(['old' => 'data']);
		$dot->setArray(['new' => 'data']);
		$this->assertSame(['new' => 'data'], $dot->all());
	}

	public function testImplementsArrayAccess(): void
	{
		$dot = new Dot();
		$dot['foo'] = 'bar';
		$this->assertTrue(isset($dot['foo']));
		$this->assertSame('bar', $dot['foo']);
		unset($dot['foo']);
		$this->assertFalse(isset($dot['foo']));
	}

	public function testImplementsCountable(): void
	{
		$dot = new Dot(['a', 'b', 'c']);
		$this->assertCount(3, $dot);
	}

	public function testImplementsIteratorAggregate(): void
	{
		$dot = new Dot(['a' => 'A', 'b' => 'B']);
		$items = [];
		foreach ($dot as $key => $value) {
			$items[$key] = $value;
		}
		$this->assertSame(['a' => 'A', 'b' => 'B'], $items);
	}

	public function testCreateStaticMethod(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$this->assertInstanceOf(Dot::class, $dot);
		$this->assertSame(['foo' => 'bar'], $dot->all());
	}

	public function testAddDoesNotOverwriteExistingKeys(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$dot->add('foo', 'baz');
		$this->assertSame('bar', $dot->get('foo'));
	}

	public function testAddAddsNewKeys(): void
	{
		$dot = new Dot(['foo' => 'bar']);
		$dot->add('baz', 'qux');
		$this->assertSame(['foo' => 'bar', 'baz' => 'qux'], $dot->all());
	}
}
