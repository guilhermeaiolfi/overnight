<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
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

		$this->assertContains('id', $result['fields']);
		$this->assertContains('name', $result['fields']);
		$this->assertEmpty($result['relations']);
	}

	public function testParseDotNotation(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$collection = $registry->getCollection('post');

		$result = $this->selector->parse($collection, 'id,author.name');

		$this->assertContains('id', $result['fields']);
		$this->assertArrayHasKey('author', $result['relations']);
		$this->assertContains('name', $result['relations']['author']['fields']);
	}

	public function testParseNestedDotNotation(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$collection = $registry->getCollection('user');

		// user -> posts -> comments
		$result = $this->selector->parse($collection, 'id,posts.comments.body');

		$this->assertContains('id', $result['fields']);
		$this->assertArrayHasKey('posts', $result['relations']);

		$postsRelation = $result['relations']['posts'];
		$this->assertArrayHasKey('comments', $postsRelation['relations']);
		$this->assertContains('body', $postsRelation['relations']['comments']['fields']);
	}

	public function testInvalidFieldThrowsError(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');

		$this->expectException(RestApiError::class);
		$this->expectExceptionMessage("Invalid field 'nonexistent'.");

		$this->selector->parse($collection, 'id,nonexistent');
	}

	public function testInvalidRelationThrowsError(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');

		$this->expectException(RestApiError::class);
		$this->expectExceptionMessage("Invalid field 'fake'.");

		$this->selector->parse($collection, 'id,fake.name');
	}

	public function testNullReturnsAllVisible(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');

		$result = $this->selector->parse($collection, null);

		// Should include all non-hidden fields
		$this->assertContains('id', $result['fields']);
		$this->assertContains('name', $result['fields']);
		$this->assertContains('email', $result['fields']);
		// password is hidden
		$this->assertNotContains('password', $result['fields']);
	}

	public function testWildcardReturnsAll(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');

		$result = $this->selector->parse($collection, '*');

		$this->assertContains('id', $result['fields']);
		$this->assertContains('name', $result['fields']);
		$this->assertContains('email', $result['fields']);
		$this->assertNotContains('password', $result['fields']);
	}

	public function testExplicitHiddenFieldsAreIgnored(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');

		$result = $this->selector->parse($collection, 'id,name,password');

		$this->assertContains('id', $result['fields']);
		$this->assertContains('name', $result['fields']);
		$this->assertNotContains('password', $result['fields']);
		$this->assertNotContains('password', $result['requestedFields']);
	}

	public function testSensibleFieldsAreHiddenFromSelection(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->field('id', 'int')->primaryKey(true)->end()
			->field('name', 'string')->end()
			->field('password', 'string')->sensible(true)->end()
			->end();
		$collection = $registry->getCollection('user');

		$this->assertTrue($collection->fields->get('password')->isHidden());

		$default = $this->selector->parse($collection, null);
		$explicit = $this->selector->parse($collection, 'id,name,password');

		$this->assertNotContains('password', $default['fields']);
		$this->assertNotContains('password', $explicit['fields']);
		$this->assertNotContains('password', $explicit['requestedFields']);
	}

	public function testPrimaryKeyAlwaysIncluded(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');

		// Request only 'name' — PK 'id' should still be included
		$result = $this->selector->parse($collection, 'name');

		$this->assertContains('id', $result['fields']);
		$this->assertContains('name', $result['fields']);
	}

	public function testParseTopLevelRelationAlias(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$collection = $registry->getCollection('user');

		$result = $this->selector->parse($collection, 'id,recent_posts.title', [
			'recent_posts' => 'posts',
		]);

		$this->assertArrayHasKey('recent_posts', $result['relations']);
		$this->assertSame('posts', $result['relations']['recent_posts']['_relation']);
		$this->assertContains('title', $result['relations']['recent_posts']['fields']);
	}

	public function testMappedColumnNameIsNotAFieldSelector(): void
	{
		$registry = new Registry();
		$this->createProfileCollection($registry);
		$collection = $registry->getCollection('profile');

		$this->expectException(RestApiError::class);
		$this->expectExceptionMessage("Invalid field 'display_name'.");

		$this->selector->parse($collection, 'display_name');
	}
}
