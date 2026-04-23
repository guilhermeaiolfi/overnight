<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Registry;
use ON\RestApi\FieldSelector;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

#[RequiresPhpExtension('pdo_sqlite')]
final class FieldSelectorTest extends TestCase
{
	use RestApiTestFixtures;

	private FieldSelector $selector;

	protected function setUp(): void
	{
		$this->selector = new FieldSelector();
	}

	public function testParseScalarFields(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');

		$result = $this->selector->parse($collection, 'id,name');

		$this->assertContains('id', $result['columns']);
		$this->assertContains('name', $result['columns']);
		$this->assertEmpty($result['relations']);
	}

	public function testParseDotNotation(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$collection = $registry->getCollection('post');

		$result = $this->selector->parse($collection, 'id,author.name');

		$this->assertContains('id', $result['columns']);
		$this->assertArrayHasKey('author', $result['relations']);
		$this->assertContains('name', $result['relations']['author']['columns']);
	}

	public function testParseNestedDotNotation(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$collection = $registry->getCollection('user');

		// user -> posts -> comments
		$result = $this->selector->parse($collection, 'id,posts.comments.body');

		$this->assertContains('id', $result['columns']);
		$this->assertArrayHasKey('posts', $result['relations']);

		$postsRelation = $result['relations']['posts'];
		$this->assertArrayHasKey('comments', $postsRelation['relations']);
		$this->assertContains('body', $postsRelation['relations']['comments']['columns']);
	}

	public function testInvalidFieldIgnored(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');

		$result = $this->selector->parse($collection, 'id,nonexistent');

		$this->assertContains('id', $result['columns']);
		// nonexistent should be silently ignored
		$this->assertNotContains('nonexistent', $result['columns']);
	}

	public function testInvalidRelationIgnored(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');

		$result = $this->selector->parse($collection, 'id,fake.name');

		$this->assertContains('id', $result['columns']);
		$this->assertArrayNotHasKey('fake', $result['relations']);
	}

	public function testNullReturnsAllVisible(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');

		$result = $this->selector->parse($collection, null);

		// Should include all non-hidden fields
		$this->assertContains('id', $result['columns']);
		$this->assertContains('name', $result['columns']);
		$this->assertContains('email', $result['columns']);
		// password is hidden
		$this->assertNotContains('password', $result['columns']);
	}

	public function testWildcardReturnsAll(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');

		$result = $this->selector->parse($collection, '*');

		$this->assertContains('id', $result['columns']);
		$this->assertContains('name', $result['columns']);
		$this->assertContains('email', $result['columns']);
		$this->assertNotContains('password', $result['columns']);
	}

	public function testPrimaryKeyAlwaysIncluded(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');

		// Request only 'name' — PK 'id' should still be included
		$result = $this->selector->parse($collection, 'name');

		$this->assertContains('id', $result['columns']);
		$this->assertContains('name', $result['columns']);
	}
}
