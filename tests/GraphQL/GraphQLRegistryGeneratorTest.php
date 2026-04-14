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
		$this->assertNotNull($schema->getMutationType());
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
		$this->expectExceptionMessage('No resolver configured');

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
		$this->expectExceptionMessage('No resolver configured');

		$resolver = $userField->resolveFn;
		$resolver(null, ['id' => '1'], null);
	}

	public function testMutationTypeExistsWithDefaultResolvers(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertNotNull($schema->getMutationType());
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

	public function testMutationFieldsCreatedForAllCollections(): void
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
		$this->assertArrayHasKey('update_user', $mutationFields);
		$this->assertArrayHasKey('delete_user', $mutationFields);
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

	public function testResolveCollectionWithoutDatabaseThrowsException(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->field('name', 'string')->type('string')->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userField = $queryType->getField('user');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No resolver configured');

		$resolver = $userField->resolveFn;
		$resolver(null, [], null);
	}

	public function testResolveByIdWithoutDatabaseThrowsException(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->field('name', 'string')->type('string')->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userField = $queryType->getField('user_by_id');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No resolver configured');

		$resolver = $userField->resolveFn;
		$resolver(null, ['id' => '1'], null);
	}

	public function testResolveCreateWithoutDatabaseWithCustomResolver(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->field('name', 'string')->type('string')->end()
			->metadata('gql::resolver::create', fn() => null)
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$mutationType = $schema->getMutationType();
		$createField = $mutationType->getField('create_user');

		$resolver = $createField->resolveFn;
		$result = $resolver(null, ['input' => '{"name":"John"}'], null);

		$this->assertNull($result);
	}

	public function testResolveUpdateWithoutDatabaseWithCustomResolver(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->field('name', 'string')->type('string')->end()
			->metadata('gql::resolver::update', fn() => null)
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$mutationType = $schema->getMutationType();
		$updateField = $mutationType->getField('update_user');

		$resolver = $updateField->resolveFn;
		$result = $resolver(null, ['id' => '1', 'input' => '{"name":"John"}'], null);

		$this->assertNull($result);
	}

	public function testResolveDeleteWithoutDatabaseWithCustomResolver(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->field('name', 'string')->type('string')->end()
			->metadata('gql::resolver::delete', fn() => null)
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$mutationType = $schema->getMutationType();
		$deleteField = $mutationType->getField('delete_user');

		$resolver = $deleteField->resolveFn;
		$result = $resolver(null, ['id' => '1'], null);

		$this->assertNull($result);
	}

	public function testFilterableFieldsAreAddedAsQueryArgs(): void
	{
		$this->registry->collection('post')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->field('title', 'string')->type('string')->end()
			->field('status', 'string')->type('string')->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$postField = $queryType->getField('post');
		$postArgs = $postField->config['args'] ?? [];

		$this->assertArrayHasKey('title', $postArgs);
		$this->assertArrayHasKey('status', $postArgs);
		$this->assertArrayHasKey('sort', $postArgs);
		$this->assertArrayHasKey('order', $postArgs);
		$this->assertArrayHasKey('limit', $postArgs);
		$this->assertArrayHasKey('offset', $postArgs);
		$this->assertArrayNotHasKey('id', $postArgs);
	}

	public function testHiddenFieldIsNotInSchema(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->field('name', 'string')->type('string')->end()
			->field('password', 'string')->type('string')->hidden(true)->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$connectionType = $queryType->getField('user')->getType();
		$userType = $connectionType->getField('items')->getType()->getWrappedType();

		$fieldNames = array_keys($userType->getFields());
		$this->assertContains('name', $fieldNames);
		$this->assertNotContains('password', $fieldNames);
	}

	public function testPrimaryKeyNotFilterable(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->field('name', 'string')->type('string')->end()
			->end();

		$collection = $this->registry->getCollection('user');

		$fieldsArray = iterator_to_array($collection->fields);
		$idField = $fieldsArray['id'];
		$nameField = $fieldsArray['name'];

		$this->assertFalse($idField->isFilterable());
		$this->assertTrue($nameField->isFilterable());
	}

	public function testBeforeMutationEventIsDispatched(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->end()
			->field('name', 'string')->type('string')->end()
			->end();

		$eventDispatched = false;
		$dispatcher = new class($eventDispatched) implements \Psr\EventDispatcher\EventDispatcherInterface {
			public function __construct(private bool &$dispatched) {}
			public function dispatch(object $event): object {
				if ($event instanceof \ON\GraphQL\Event\BeforeMutation) {
					$this->dispatched = true;
				}
				return $event;
			}
		};

		// Create a mock resolver that returns a stdClass
		$mockResolver = new class implements \ON\GraphQL\Resolver\GraphQLResolverInterface {
			public function resolveCollection(\ON\ORM\Definition\Collection\Collection $c, array $a = []): array { return ['items' => [], 'totalCount' => 0]; }
			public function resolveById(\ON\ORM\Definition\Collection\Collection $c, string $id): ?object { return null; }
			public function resolveCreate(\ON\ORM\Definition\Collection\Collection $c, array $input): ?object { return (object) $input; }
			public function resolveUpdate(\ON\ORM\Definition\Collection\Collection $c, string $id, array $input): ?object { return null; }
			public function resolveDelete(\ON\ORM\Definition\Collection\Collection $c, string $id): ?object { return null; }
			public function resolveNestedCreate(\ON\ORM\Definition\Collection\Collection $c, array $input, array $nested): ?object { return null; }
			public function resolveRelation(mixed $source, \ON\ORM\Definition\Relation\RelationInterface $r): mixed { return null; }
			public function clearCache(): void {}
		};

		$generator = new GraphQLRegistryGenerator($this->registry, $mockResolver, $dispatcher);
		$schema = $generator->generate();

		$mutationType = $schema->getMutationType();
		$createField = $mutationType->getField('create_user');
		$resolver = $createField->resolveFn;
		$resolver(null, ['input' => ['name' => 'Test']], null);

		$this->assertTrue($eventDispatched);
	}
}
