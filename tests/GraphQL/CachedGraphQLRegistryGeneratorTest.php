<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL;

use GraphQL\Type\Schema;
use ON\GraphQL\CachedGraphQLRegistryGenerator;
use ON\GraphQL\GraphQLRegistryGenerator;
use ON\ORM\Definition\Registry;
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
			->field('id', 'int')->type('int')->primaryKey(true)->end()
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
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->end();

		$generator = new CachedGraphQLRegistryGenerator($this->registry);
		$generator->generate();

		$this->assertTrue($generator->isCached());
	}

	public function testInvalidateClearsCache(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
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
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->field('title', 'string')->type('string')->end()
			->end();

		$generator = new CachedGraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertInstanceOf(Schema::class, $schema);
		$this->assertNotNull($schema->getQueryType());
	}
}
