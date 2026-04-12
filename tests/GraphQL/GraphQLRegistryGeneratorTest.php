<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL;

use GraphQL\Type\Schema;
use ON\GraphQL\GraphQLRegistryGenerator;
use ON\ORM\Definition\Registry;
use PHPUnit\Framework\TestCase;

final class GraphQLRegistryGeneratorTest extends TestCase
{
	private Registry $registry;

	protected function setUp(): void
	{
		$this->registry = new Registry();
	}

	public function testCanBeInstantiated(): void
	{
		$generator = new GraphQLRegistryGenerator($this->registry);
		$this->assertInstanceOf(GraphQLRegistryGenerator::class, $generator);
	}

	public function testGenerateSchemaWithoutCollections(): void
	{
		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertInstanceOf(Schema::class, $schema);
	}

	public function testGenerateSchemaWithCollection(): void
	{
		$collection = $this->registry->collection('user');
		$collection->field('id', 'int')->type('int')->primaryKey(true)->end();
		$collection->field('name', 'string')->type('string')->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertInstanceOf(Schema::class, $schema);
		$this->assertNotNull($schema->getQueryType());
		$this->assertNull($schema->getMutationType());
	}

	public function testGenerateSchemaWithMultipleCollections(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->field('name', 'string')->type('string')->end()
			->end();

		$this->registry->collection('post')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->field('title', 'string')->type('string')->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertInstanceOf(Schema::class, $schema);
	}

	public function testHiddenCollectionIsNotIncluded(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->end();

		$this->registry->collection('hidden')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->hidden(true)
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertInstanceOf(Schema::class, $schema);
	}

	public function testCollectionWithMetadataResolver(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->metadata('gql::resolver::findAll', fn ($args, $container) => [])
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertInstanceOf(Schema::class, $schema);
	}

	public function testFieldWithTypeOverride(): void
	{
		$this->registry->collection('user')
			->field('email', 'string')->type('string')
				->metadata('gql::type', 'EmailType!')
				->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertInstanceOf(Schema::class, $schema);
	}

	public function testFindAllResolverNotDefinedThrowsException(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userField = $queryType->getField('user');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Resolver for collection "user::findAll" is not defined');

		$resolver = $userField->resolveFn;
		$resolver(null, [], null);
	}

	public function testFindByIdResolverNotDefinedThrowsException(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userField = $queryType->getField('user_by_id');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Resolver for collection "user::findById" is not defined');

		$resolver = $userField->resolveFn;
		$resolver(null, ['id' => '1'], null);
	}

	public function testMutationTypeIsNullWhenNoResolvers(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertNull($schema->getMutationType());
	}

	public function testMutationTypeCreatedWithAnyResolver(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->metadata('gql::resolver::update', fn () => null)
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertNotNull($schema->getMutationType());
		$this->assertNotNull($schema->getMutationType()->getField('update_user'));
	}

	public function testMutationFieldsCreatedOnlyWithResolvers(): void
	{
		$customResolver = fn () => null;

		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->metadata('gql::resolver::create', $customResolver)
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$mutationType = $schema->getMutationType();
		$this->assertNotNull($mutationType);
		$mutationFields = $mutationType->config['fields'] ?? [];
		$this->assertArrayHasKey('create_user', $mutationFields);
		$this->assertArrayNotHasKey('update_user', $mutationFields);
		$this->assertArrayNotHasKey('delete_user', $mutationFields);
	}

	public function testCustomResolverIsCalled(): void
	{
		$called = false;
		$customResolver = function ($args, $container) use (&$called) {
			$called = true;
			return [];
		};

		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->metadata('gql::resolver::findAll', $customResolver)
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userField = $queryType->getField('user');

		$resolver = $userField->resolveFn;
		$result = $resolver(null, [], null);

		$this->assertTrue($called);
	}

	public function testCreateMutationWithCustomResolver(): void
	{
		$called = false;
		$customResolver = function ($args, $container) use (&$called) {
			$called = true;
			return new \stdClass();
		};

		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->metadata('gql::resolver::create', $customResolver)
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$mutationType = $schema->getMutationType();
		$createField = $mutationType->getField('create_user');

		$resolver = $createField->resolveFn;
		$resolver(null, ['input' => '{}'], null);

		$this->assertTrue($called);
	}
}