<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\ON\Fixtures\DummyLoader;
use Tests\ON\Fixtures\UserPartsRegistry;

final class ColumnTraitTest extends TestCase
{
	private $registry;
	private $dummyLoader;

	protected function setUp(): void
	{
		$this->registry = new UserPartsRegistry();
		$this->dummyLoader = new DummyLoader($this->registry);
	}

	public function testSimpleFields(): void
	{
		$columns = $this->dummyLoader->resolveColumns($this->registry->getCollection('users'), [
			"id" => true,
		]);

		$this->assertSame([
			"id" => "id",
		], $columns);


		$columns = $this->dummyLoader->resolveColumns($this->registry->getCollection('users'), [
			"name" => true,
		]);

		$this->assertSame([
			"name" => "name",
		], $columns);

		$columns = $this->dummyLoader->resolveColumns($this->registry->getCollection('users'), [
			"id" => true,
			"name" => true,
		]);

		$this->assertSame([
			"id" => "id",
			"name" => "name",
		], $columns);
	}

	public function testStarFilter(): void
	{
		$columns = $this->dummyLoader->resolveColumns($this->registry->getCollection('users'), ['*']);

		$this->assertSame([
			"id" => "id",
			"name" => "name",
		], $columns);
	}

	public function testEmptyFilter(): void
	{
		$columns = $this->dummyLoader->resolveColumns($this->registry->getCollection('users'), []);

		$this->assertSame([
		], $columns);
	}

	public function testExcludeFilter(): void
	{
		$columns = $this->dummyLoader->resolveColumns($this->registry->getCollection('users'), [
			"id" => false,
		]);

		$this->assertSame([
			"name" => "name",
		], $columns);
	}

	public function testAllFilter(): void
	{
		$columns = $this->dummyLoader->resolveColumns($this->registry->getCollection('users'), null);

		$this->assertSame([
			"id" => "id",
			"name" => "name",
		], $columns);
	}
}
