<?php

declare(strict_types=1);

namespace Tests\ON\View;

use ON\View\ViewResult;
use PHPUnit\Framework\TestCase;

final class ViewResultTest extends TestCase
{
	public function testConstructWithViewAndData(): void
	{
		$result = new ViewResult('success', ['post' => ['id' => 1], 'message' => 'Created']);

		$this->assertSame('success', $result->getViewName());
		$this->assertSame(['post' => ['id' => 1], 'message' => 'Created'], $result->toArray());
	}

	public function testConstructWithViewOnly(): void
	{
		$result = new ViewResult('error');

		$this->assertSame('error', $result->getViewName());
		$this->assertSame([], $result->toArray());
	}

	public function testGet(): void
	{
		$result = new ViewResult('success', ['name' => 'John', 'age' => 30]);

		$this->assertSame('John', $result->get('name'));
		$this->assertSame(30, $result->get('age'));
		$this->assertNull($result->get('missing'));
		$this->assertSame('default', $result->get('missing', 'default'));
	}

	public function testHas(): void
	{
		$result = new ViewResult('success', ['name' => 'John', 'nullable' => null]);

		$this->assertTrue($result->has('name'));
		$this->assertTrue($result->has('nullable'));
		$this->assertFalse($result->has('missing'));
	}

	public function testToArray(): void
	{
		$data = ['a' => 1, 'b' => 'two', 'c' => [3]];
		$result = new ViewResult('test', $data);

		$this->assertSame($data, $result->toArray());
	}

	public function testArrayAccessRead(): void
	{
		$result = new ViewResult('success', ['title' => 'Hello']);

		$this->assertTrue(isset($result['title']));
		$this->assertFalse(isset($result['missing']));
		$this->assertSame('Hello', $result['title']);
	}

	public function testArrayAccessSetThrows(): void
	{
		$result = new ViewResult('success');

		$this->expectException(\LogicException::class);
		$result['key'] = 'value';
	}

	public function testArrayAccessUnsetThrows(): void
	{
		$result = new ViewResult('success', ['key' => 'value']);

		$this->expectException(\LogicException::class);
		unset($result['key']);
	}
}
