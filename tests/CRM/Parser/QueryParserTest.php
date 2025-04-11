<?php

declare(strict_types=1);

use ON\CMS\Parser\QueryParser;
use PHPUnit\Framework\TestCase;
use Tests\ON\Fixtures\UserPartsRegistry;

final class QueryParserTest extends TestCase
{
	private $registry;

	protected function setUp(): void
	{
		$this->registry = new UserPartsRegistry();
	}

	public function testSimpleFields(): void
	{
		$string = 'users{id,parts}';

		$parser = new QueryParser($this->registry);
		$rootNode = $parser->parse($string);

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

		$parser = new QueryParser($this->registry);

		$rootNode = $parser->parse($string);

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

		$parser = new QueryParser($this->registry);
		$rootNode = $parser->parse($string);


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

		$parser = new QueryParser($this->registry);
		$rootNode = $parser->parse($string);

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

		$parser = new QueryParser($this->registry);
		$rootNode = $parser->parse($string);

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

		$parser = new QueryParser($this->registry);
		$rootNode = $parser->parse($string);

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

		$parser = new QueryParser($this->registry);
		$rootNode = $parser->parse($string);

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

	public function testFieldModifiers(): void
	{
		$string = 'users{-name}';

		$parser = new QueryParser($this->registry);
		$rootNode = $parser->parse($string);

		$this->assertSame([
			"users" => [
				"name" => "-name",
			],
		], $rootNode->toArray());
	}

	public function testStarField(): void
	{
		$string = 'users{*}';

		$parser = new QueryParser($this->registry);
		$rootNode = $parser->parse($string);

		$this->assertSame([
			"users" => [
				"*" => "*",
			],
		], $rootNode->toArray());
	}
}
