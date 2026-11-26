<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use ON\Data\Definition\Registry;
use ON\GraphQL\CachedGraphQLRegistryGenerator;
use ON\GraphQL\GraphQLRegistryGenerator;
use PHPUnit\Framework\TestCase;

final class CachedGraphQLRegistryGeneratorTest extends TestCase
{
	private Registry $registry;

	protected function setUp(): void
	{
		$this->registry = new Registry();
	}

	public function testReturnsSameSchemaOnMultipleCalls(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->field('name', 'string')->type('string')->end()
			->end();

		$generator = new CachedGraphQLRegistryGenerator($this->registry);

		$schema1 = $generator->generate();
		$schema2 = $generator->generate();

		$this->assertSame($schema1, $schema2);
	}

	public function testIsCachedReturnsFalseBeforeGenerate(): void
	{
		$generator = new CachedGraphQLRegistryGenerator($this->registry);

		$this->assertFalse($generator->isCached());
	}

	public function testIsCachedReturnsTrueAfterGenerate(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->end();

		$generator = new CachedGraphQLRegistryGenerator($this->registry);
		$generator->generate();

		$this->assertTrue($generator->isCached());
	}

	public function testInvalidateClearsCache(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->end();

		$generator = new CachedGraphQLRegistryGenerator($this->registry);
		$schema1 = $generator->generate();

		$this->assertTrue($generator->isCached());

		$generator->invalidate();

		$this->assertFalse($generator->isCached());

		$schema2 = $generator->generate();

		// After invalidation, a new schema is generated (not the same instance)
		$this->assertNotSame($schema1, $schema2);
	}

	public function testIsDropInReplacementForGenerator(): void
	{
		$generator = new CachedGraphQLRegistryGenerator($this->registry);

		$this->assertInstanceOf(GraphQLRegistryGenerator::class, $generator);
	}

	public function testGeneratedSchemaIsValid(): void
	{
		$this->registry->collection('post')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->field('title', 'string')->type('string')->end()
			->end();

		$generator = new CachedGraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertInstanceOf(Schema::class, $schema);
		$this->assertNotNull($schema->getQueryType());
	}

	public function testFieldVisibilityChangesInvalidateTheCachedSchema(): void
	{
		$collection = $this->registry->collection('post')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end();
		$generator = new CachedGraphQLRegistryGenerator($this->registry);
		$firstSchema = $generator->generate();
		$this->assertArrayHasKey('title', $firstSchema->getType('Post')->getFields());

		$collection->fields->get('title')->hidden(true);
		$nextSchema = $generator->generate();

		$this->assertNotSame($firstSchema, $nextSchema);
		$this->assertArrayNotHasKey('title', $nextSchema->getType('Post')->getFields());
	}

	public function testLazyTypeOverridesAreCachedAndChangesRegenerateTheSchema(): void
	{
		$collection = $this->registry->collection('post')->primaryKey('id')
			->field('id', 'int')->end()
			->field('rating', 'string')->metadata('gql::type', static fn () => Type::int())->end();
		$generator = new CachedGraphQLRegistryGenerator($this->registry);

		$first = $generator->generate();
		$this->assertSame(Type::int(), $first->getType('Post')->getField('rating')->getType());
		$this->assertSame($first, $generator->generate());

		$collection->fields->get('rating')->metadata('gql::type', static fn () => Type::float());
		$second = $generator->generate();
		$this->assertNotSame($first, $second);
		$this->assertSame(Type::float(), $second->getType('Post')->getField('rating')->getType());
	}
}
