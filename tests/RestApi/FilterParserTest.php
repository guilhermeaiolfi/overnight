<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Registry;
use ON\RestApi\Resolver\Sql\SqlFilterParser;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

#[RequiresPhpExtension('pdo_sqlite')]
final class FilterParserTest extends TestCase
{
	use RestApiTestFixtures;

	private SqlFilterParser $parser;
	private Registry $registry;

	protected function setUp(): void
	{
		$this->parser = new SqlFilterParser();
		$this->registry = new Registry();
		$this->createFullSchema($this->registry);
	}

	public function testEqOperator(): void
	{
		$collection = $this->registry->getCollection('user');
		$result = $this->parser->parse($collection, ['name' => ['_eq' => 'John']]);

		$this->assertSame('WHERE `user`.`name` = ?', $result['sql']);
		$this->assertSame(['John'], $result['values']);
	}

	public function testNeqOperator(): void
	{
		$collection = $this->registry->getCollection('user');
		$result = $this->parser->parse($collection, ['name' => ['_neq' => 'John']]);

		$this->assertSame('WHERE `user`.`name` != ?', $result['sql']);
		$this->assertSame(['John'], $result['values']);
	}

	public function testLtLteGtGte(): void
	{
		$collection = $this->registry->getCollection('post');

		$result = $this->parser->parse($collection, ['id' => ['_lt' => '5']]);
		$this->assertSame('WHERE `post`.`id` < ?', $result['sql']);
		$this->assertSame(['5'], $result['values']);

		$result = $this->parser->parse($collection, ['id' => ['_lte' => '5']]);
		$this->assertSame('WHERE `post`.`id` <= ?', $result['sql']);

		$result = $this->parser->parse($collection, ['id' => ['_gt' => '1']]);
		$this->assertSame('WHERE `post`.`id` > ?', $result['sql']);

		$result = $this->parser->parse($collection, ['id' => ['_gte' => '1']]);
		$this->assertSame('WHERE `post`.`id` >= ?', $result['sql']);
	}

	public function testInOperator(): void
	{
		$collection = $this->registry->getCollection('post');
		$result = $this->parser->parse($collection, ['status' => ['_in' => 'published,draft']]);

		$this->assertSame('WHERE `post`.`status` IN (?, ?)', $result['sql']);
		$this->assertSame(['published', 'draft'], $result['values']);
	}

	public function testNinOperator(): void
	{
		$collection = $this->registry->getCollection('post');
		$result = $this->parser->parse($collection, ['status' => ['_nin' => 'draft']]);

		$this->assertSame('WHERE `post`.`status` NOT IN (?)', $result['sql']);
		$this->assertSame(['draft'], $result['values']);
	}

	public function testNullNnull(): void
	{
		$collection = $this->registry->getCollection('user');

		$result = $this->parser->parse($collection, ['email' => ['_null' => true]]);
		$this->assertSame('WHERE `user`.`email` IS NULL', $result['sql']);
		$this->assertSame([], $result['values']);

		$result = $this->parser->parse($collection, ['email' => ['_nnull' => true]]);
		$this->assertSame('WHERE `user`.`email` IS NOT NULL', $result['sql']);
		$this->assertSame([], $result['values']);
	}

	public function testContains(): void
	{
		$collection = $this->registry->getCollection('user');
		$result = $this->parser->parse($collection, ['name' => ['_contains' => 'oh']]);

		$this->assertSame('WHERE `user`.`name` LIKE ?', $result['sql']);
		$this->assertSame(['%oh%'], $result['values']);
	}

	public function testStartsWith(): void
	{
		$collection = $this->registry->getCollection('user');
		$result = $this->parser->parse($collection, ['name' => ['_starts_with' => 'Jo']]);

		$this->assertSame('WHERE `user`.`name` LIKE ?', $result['sql']);
		$this->assertSame(['Jo%'], $result['values']);
	}

	public function testEndsWith(): void
	{
		$collection = $this->registry->getCollection('user');
		$result = $this->parser->parse($collection, ['name' => ['_ends_with' => 'hn']]);

		$this->assertSame('WHERE `user`.`name` LIKE ?', $result['sql']);
		$this->assertSame(['%hn'], $result['values']);
	}

	public function testBetween(): void
	{
		$collection = $this->registry->getCollection('post');
		$result = $this->parser->parse($collection, ['id' => ['_between' => '1,3']]);

		$this->assertSame('WHERE `post`.`id` BETWEEN ? AND ?', $result['sql']);
		$this->assertSame(['1', '3'], $result['values']);
	}

	public function testEmpty(): void
	{
		$collection = $this->registry->getCollection('user');
		$result = $this->parser->parse($collection, ['email' => ['_empty' => true]]);

		$this->assertSame("WHERE (`user`.`email` IS NULL OR `user`.`email` = '')", $result['sql']);
		$this->assertSame([], $result['values']);
	}

	public function testNempty(): void
	{
		$collection = $this->registry->getCollection('user');
		$result = $this->parser->parse($collection, ['email' => ['_nempty' => true]]);

		$this->assertSame("WHERE (`user`.`email` IS NOT NULL AND `user`.`email` != '')", $result['sql']);
		$this->assertSame([], $result['values']);
	}

	public function testOrLogical(): void
	{
		$collection = $this->registry->getCollection('user');
		$result = $this->parser->parse($collection, [
			'_or' => [
				['name' => ['_eq' => 'John']],
				['name' => ['_eq' => 'Jane']],
			],
		]);

		$this->assertSame('WHERE ((`user`.`name` = ?) OR (`user`.`name` = ?))', $result['sql']);
		$this->assertSame(['John', 'Jane'], $result['values']);
	}

	public function testAndLogical(): void
	{
		$collection = $this->registry->getCollection('post');
		$result = $this->parser->parse($collection, [
			'_and' => [
				['status' => ['_eq' => 'published']],
				['user_id' => ['_eq' => '1']],
			],
		]);

		$this->assertSame('WHERE ((`post`.`status` = ?) AND (`post`.`user_id` = ?))', $result['sql']);
		$this->assertSame(['published', '1'], $result['values']);
	}

	public function testInvalidFieldIgnored(): void
	{
		$collection = $this->registry->getCollection('user');
		$result = $this->parser->parse($collection, ['nonexistent' => ['_eq' => 'x']]);

		$this->assertSame('', $result['sql']);
		$this->assertSame([], $result['values']);
	}

	public function testSqlInjectionPrevented(): void
	{
		$collection = $this->registry->getCollection('user');

		// Field name with SQL injection attempt — should be sanitized by quoteIdentifier
		$quoted = $this->parser->quoteIdentifier("name; DROP TABLE user--");
		$this->assertSame('`nameDROPTABLEuser`', $quoted);

		// The field won't exist in the collection, so it should be ignored
		$result = $this->parser->parse($collection, ['name; DROP TABLE user--' => ['_eq' => 'x']]);
		$this->assertSame('', $result['sql']);
		$this->assertSame([], $result['values']);
	}

	public function testRelationFilterProducesExistsSubquery(): void
	{
		$collection = $this->registry->getCollection('post');
		$result = $this->parser->parse($collection, ['author.name' => ['_eq' => 'John']]);

		$this->assertStringContainsString('EXISTS (SELECT 1 FROM `user`', $result['sql']);
		$this->assertStringContainsString('`post__author`.`name` = ?', $result['sql']);
		$this->assertSame(['John'], $result['values']);
	}

	public function testManyToManyRelationFilterProducesJunctionExistsSubquery(): void
	{
		$collection = $this->registry->getCollection('post');
		$result = $this->parser->parse($collection, ['tags.name' => ['_eq' => 'GraphQL']]);

		$this->assertStringContainsString('FROM `post_tag`', $result['sql']);
		$this->assertStringContainsString('INNER JOIN `tag`', $result['sql']);
		$this->assertSame(['GraphQL'], $result['values']);
	}
}
