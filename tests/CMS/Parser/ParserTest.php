<?php

declare(strict_types=1);

use ON\CMS\Definition\Registry;
use ON\CMS\Parser\Parser;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
	private $registry;

	protected function setUp(): void
	{
		$this->registry = new Registry();
		$this->registry
		->collection("users")
			->field("id")->end()
			->field("name")->end()
			->relation("parts")
				->collection('parts')
			->end()
			->relation("user")
				->collection("users")
			->end()
			->relation("foo")
				->collection("foos")
			->end()
		->end()
		->collection("foos")
			->field("id")->end()
			->field("name")->end()
			->relation("bar")
				->collection("bars")
			->end()
		->end()
		->collection("bars")
			->field("id")->end()
			->field("name")->end()
			->relation('user')
				->collection('users')
			->end()
		->end()
		->collection("parts")
			->field("id")->end()
			->field("name")->end()
			->relation('user')
				->collection('users')
			->end()
		->end();

	}

	public function testSimpleFields(): void
	{
		$string = 'users{id,parts}';

		$parser = new Parser();
		$rootNode = $parser->parse($string, $this->registry);

		$this->assertSame([
			"users" => [
				"id" => "id",
				"parts" => "parts",
			],
		], $rootNode->toArray());
	}

	public function testShallowRelation(): void
	{
		$string = 'users{id,parts{id,name}}';

		$parser = new Parser();

		$rootNode = $parser->parse($string, $this->registry);

		//var_dump($rootNode->toArray());
		$this->assertSame([
			"users" => [
				"id" => "id",
				"parts" => [
					"id" => "id",
					"name" => "name",
				],
			],
		], $rootNode->toArray());
	}

	public function testDeepRelationWithFields(): void
	{

		$string = 'users{id,parts{id,name,user{id,name}}}';

		$parser = new Parser();
		$rootNode = $parser->parse($string, $this->registry);


		$this->assertSame([
			"users" => [
				"id" => "id",
				"parts" => [
					"id" => "id",
					"name" => "name",
					"user" => [
						"id" => "id",
						"name" => "name",
					],
				],
			],
		], $rootNode->toArray());
	}

	public function testDeepRelationWithFieldsInTheEnd(): void
	{
		$string = 'users{id,parts{id,name,user{id,name}},name}';

		$parser = new Parser();
		$rootNode = $parser->parse($string, $this->registry);

		$this->assertSame([
			"users" => [
				"id" => "id",
				"parts" => [
					"id" => "id",
					"name" => "name",
					"user" => [
						"id" => "id",
						"name" => "name",
					],
				],
				"name" => "name",
			],
		], $rootNode->toArray());
	}

	public function testManyRelations(): void
	{
		$string = 'users{id,parts{id,name},name,user{id,name}}';

		$parser = new Parser();
		$rootNode = $parser->parse($string, $this->registry);

		$this->assertSame([
			"users" => [
				"id" => "id",
				"parts" => [
					"id" => "id",
					"name" => "name",
				],
				"name" => "name",
				"user" => [
					"id" => "id",
					"name" => "name",
				],
			],
		], $rootNode->toArray());
	}

	public function testManyRelationsWithShallowRelation(): void
	{
		$string = 'users{id,parts{id,name},name,parts.user{id,name}}';

		$parser = new Parser();
		$rootNode = $parser->parse($string, $this->registry);

		$this->assertSame([
			"users" => [
				"id" => "id",
				"parts" => [
					"id" => "id",
					"name" => "name",
					"user" => [
						"id" => "id",
						"name" => "name",
					],
				],
				"name" => "name",
			],
		], $rootNode->toArray());
	}

	public function testManyRelationsWithDeepShallowRelation(): void
	{
		$string = 'users{id,parts{id,name},name,foo.bar.user{id,name}}';

		$parser = new Parser();
		$rootNode = $parser->parse($string, $this->registry);

		$this->assertSame([
			"users" => [
				"id" => "id",
				"parts" => [
					"id" => "id",
					"name" => "name",
				],
				"name" => "name",
				"foo" => [
					"bar" => [
						"user" => [
							"id" => "id",
							"name" => "name",
						],
					],
				],
			],
		], $rootNode->toArray());
	}
}
