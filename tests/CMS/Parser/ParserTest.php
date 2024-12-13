<?php

declare(strict_types=1);
use ON\CMS\Parser\Parser;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
	public function testSimpleFields(): void
	{
		$string = 'id,parts';

		$parser = new Parser();
		$rootNode = $parser->parse($string, "users");

		$this->assertSame([
			"users" => [
				"id" => "id",
				"parts" => "parts",
			],
		], $rootNode->toArray());
	}

	public function testShallowRelation(): void
	{
		$string = 'id,parts{id,name}';

		$parser = new Parser();
		$rootNode = $parser->parse($string, "users");

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
		$string = 'id,parts{id,name,user{id,name}}';

		$parser = new Parser();
		$rootNode = $parser->parse($string, "users");


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
		$string = 'id,parts{id,name,user{id,name}},name';

		$parser = new Parser("users");
		$rootNode = $parser->parse($string, "users");

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
		$string = 'id,parts{id,name},name,user{id,name}';

		$parser = new Parser();
		$rootNode = $parser->parse($string, "users");

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
		$string = 'id,parts{id,name},name,parts.user{id,name}';

		$parser = new Parser("users");
		$rootNode = $parser->parse($string, "users");

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
		$string = 'id,parts{id,name},name,foo.bar.user{id,name}';

		$parser = new Parser("users");
		$rootNode = $parser->parse($string, "users");

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
