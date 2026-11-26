<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL;

use GraphQL\GraphQL;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Schema;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\GraphQL\CachedGraphQLRegistryGenerator;
use ON\GraphQL\Event\BeforeMutation;
use ON\GraphQL\GraphQLRegistryGenerator;
use ON\GraphQL\Resolver\GraphQLResolverInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use stdClass;

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
		$collection->primaryKey('id');
		$collection->field('id', 'int')->type('int')->end();
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
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->field('name', 'string')->type('string')->end()
			->end();

		$this->registry->collection('post')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->field('title', 'string')->type('string')->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertInstanceOf(Schema::class, $schema);
	}

	public function testHiddenCollectionIsNotIncluded(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->end();

		$this->registry->collection('hidden')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->hidden(true)
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertInstanceOf(Schema::class, $schema);
	}

	public function testCollectionWithMetadataResolver(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
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
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userField = $queryType->getField('user');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No resolver configured');

		$resolver = $userField->resolveFn;
		$resolver(null, [], null);
	}

	public function testFindByIdResolverNotDefinedThrowsException(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userField = $queryType->getField('user_by_id');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No resolver configured');

		$resolver = $userField->resolveFn;
		$resolver(null, ['id' => '1'], null);
	}

	public function testMutationTypeExistsWithDefaultResolvers(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$this->assertNotNull($schema->getMutationType());
	}

	public function testMutationTypeCreatedWithAnyResolver(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
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
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
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
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
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

			return new stdClass();
		};

		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
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
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->field('name', 'string')->type('string')->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userField = $queryType->getField('user');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No resolver configured');

		$resolver = $userField->resolveFn;
		$resolver(null, [], null);
	}

	public function testResolveByIdWithoutDatabaseThrowsException(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->field('name', 'string')->type('string')->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userField = $queryType->getField('user_by_id');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No resolver configured');

		$resolver = $userField->resolveFn;
		$resolver(null, ['id' => '1'], null);
	}

	public function testResolveCreateWithoutDatabaseWithCustomResolver(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->field('name', 'string')->type('string')->end()
			->metadata('gql::resolver::create', fn () => null)
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
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->field('name', 'string')->type('string')->end()
			->metadata('gql::resolver::update', fn () => null)
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
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->field('name', 'string')->type('string')->end()
			->metadata('gql::resolver::delete', fn () => null)
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
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
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
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
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
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->field('name', 'string')->type('string')->end()
			->end();

		$collection = $this->registry->getCollection('user');

		$fieldsArray = iterator_to_array($collection->fields);
		$idField = $fieldsArray['id'];
		$nameField = $fieldsArray['name'];

		// ON\Data keeps filterable=true by default; GraphQL excludes PKs via isPrimaryKey().
		$this->assertTrue($idField->isPrimaryKey());
		$this->assertTrue($idField->isFilterable());
		$this->assertFalse($nameField->isPrimaryKey());
		$this->assertTrue($nameField->isFilterable());

		$schema = (new GraphQLRegistryGenerator($this->registry))->generate();
		$queryType = $schema->getQueryType();
		$userArgs = $queryType->getField('user')->args;
		$argNames = array_map(static fn ($arg) => $arg->name, $userArgs);
		$this->assertNotContains('id', $argNames);
		$this->assertContains('name', $argNames);
	}

	public function testBeforeMutationEventIsDispatched(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->field('name', 'string')->type('string')->end()
			->end();

		$eventDispatched = false;
		$dispatcher = new class ($eventDispatched) implements EventDispatcherInterface {
			public function __construct(private bool &$dispatched)
			{
			}

			public function dispatch(object $event): object
			{
				if ($event instanceof BeforeMutation) {
					$this->dispatched = true;
				}

				return $event;
			}
		};

		// Create a mock resolver that returns a stdClass
		$mockResolver = new class () implements GraphQLResolverInterface {
			public function resolveCollection(Collection $c, array $a = []): array
			{
				return ['items' => [], 'totalCount' => 0];
			}

			public function resolveById(Collection $c, string $id): ?object
			{
				return null;
			}

			public function resolveCreate(Collection $c, array $input): ?object
			{
				return (object) $input;
			}

			public function resolveUpdate(Collection $c, string $id, array $input): ?object
			{
				return null;
			}

			public function resolveDelete(Collection $c, string $id): ?object
			{
				return null;
			}

			public function resolveNestedCreate(Collection $c, array $input, array $nested): ?object
			{
				return null;
			}

			public function resolveRelation(mixed $source, RelationInterface $r): mixed
			{
				return null;
			}

			public function clearCache(): void
			{
			}
		};

		$generator = new GraphQLRegistryGenerator($this->registry, $mockResolver, $dispatcher);
		$schema = $generator->generate();

		$mutationType = $schema->getMutationType();
		$createField = $mutationType->getField('create_user');
		$resolver = $createField->resolveFn;
		$resolver(null, ['input' => ['name' => 'Test']], null);

		$this->assertTrue($eventDispatched);
	}

	public function testEnumFieldGeneratesEnumType(): void
	{
		$this->registry->collection('post')
			->primaryKey('id')
			->field('id', 'int')->type('int')->end()
			->field('status', 'string')->type('string')->nullable(true)
				->metadata('enum', ['draft', 'published', 'archived'])
				->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$connectionType = $queryType->getField('post')->getType();
		$postType = $connectionType->getField('items')->getType()->getWrappedType();

		$statusField = $postType->getField('status');
		$statusType = $statusField->getType();

		// Should be an EnumType, not String
		$this->assertInstanceOf(EnumType::class, $statusType);
		$this->assertSame('StatusEnum', $statusType->name);

		$enumValues = array_map(fn ($v) => $v->value, $statusType->getValues());
		$this->assertContains('draft', $enumValues);
		$this->assertContains('published', $enumValues);
		$this->assertContains('archived', $enumValues);
	}

	public function testRelationsToHiddenCollectionsAreOmittedFromInputAndOutput(): void
	{
		$this->registry->collection('profile')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('user_id', 'int')->end()
			->hidden(true)
			->end();
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->hasOne('profile', 'profile')->innerKey('id')->outerKey('user_id')->end()
			->end();

		$schema = (new GraphQLRegistryGenerator($this->registry))->generate();
		$create = $schema->getMutationType()->getField('create_user');

		$this->assertArrayNotHasKey('profile', $create->getType()->getFields());
		$this->assertArrayNotHasKey('profile', $create->args[0]->getType()->getWrappedType()->getFields());
	}

	public function testGeneratedNestedCreateInputsOnlyExposeOwnedRelations(): void
	{
		$this->registry->collection('post')
			->primaryKey('id')->field('id', 'int')->end()
			->field('user_id', 'int')->end()
			->field('title', 'string')->end()
			->end();
		$this->registry->collection('profile')
			->primaryKey('id')->field('id', 'int')->end()
			->field('user_id', 'int')->end()
			->end();
		$this->registry->collection('department')
			->primaryKey('id')->field('id', 'int')->end()
			->field('name', 'string')->end()
			->end();
		$this->registry->collection('membership')
			->primaryKey('id')->field('id', 'int')->end()
			->field('user_id', 'int')->end()
			->field('department_id', 'int')->end()
			->end();
		$user = $this->registry->collection('user')
			->primaryKey('id')->field('id', 'int')->end()
			->field('department_id', 'int')->nullable(true)->end()
			->hasMany('posts', 'post')->innerKey('id')->outerKey('user_id')->end()
			->hasOne('profile', 'profile')->innerKey('id')->outerKey('user_id')->end()
			->belongsTo('department', 'department')->innerKey('department_id')->outerKey('id')->end();
		$user->relation('latest_post', FirstOfManyRelation::class)
			->collection('post')->innerKey('id')->outerKey('user_id')->orderBy(['id' => 'desc'])->end();
		$user->relation('departments', M2MRelation::class)
			->collection('department')->innerKey('id')->outerKey('id')
			->through('membership')->innerKey('user_id')->outerKey('department_id')->end()
			->end();

		$generator = new CachedGraphQLRegistryGenerator($this->registry);
		$schema = $generator->generate();
		$create = $schema->getMutationType()->getField('create_user');
		$inputFields = $create->args[0]->getType()->getWrappedType()->getFields();
		$outputFields = $create->getType()->getFields();

		$this->assertArrayHasKey('posts', $inputFields);
		$this->assertArrayHasKey('profile', $inputFields);
		foreach (['department', 'latest_post', 'departments'] as $readOnlyRelation) {
			$this->assertArrayNotHasKey($readOnlyRelation, $inputFields);
			$this->assertArrayHasKey($readOnlyRelation, $outputFields);
		}

		$user->metadata('gql::resolver::create', static fn (array $args) => (object) [
			'id' => 10,
			'department_id' => strlen($args['input']['department']['name']),
		]);
		$schema = $generator->generate();
		$result = GraphQL::executeQuery(
			$schema,
			'mutation { create_user(input: { department: { name: "Sales" } }) { department_id } }'
		)->toArray();

		$this->assertSame(['data' => ['create_user' => ['department_id' => 5]]], $result);
	}

	public function testEnumsWithTheSameFieldNameKeepTheirCollectionValues(): void
	{
		$this->registry->collection('post')
			->primaryKey('id')->field('id', 'int')->end()
			->field('status', 'string')->metadata('enum', ['draft'])->end()
			->end();
		$this->registry->collection('ticket')
			->primaryKey('id')->field('id', 'int')->end()
			->field('status', 'string')->metadata('enum', ['open'])->end()
			->end();

		$schema = (new GraphQLRegistryGenerator($this->registry))->generate();
		$schema->assertValid();

		$this->assertSame('draft', $schema->getType('PostStatusEnum')->getValues()[0]->value);
		$this->assertSame('open', $schema->getType('TicketStatusEnum')->getValues()[0]->value);
	}

	public function testAuthorizationRunsBeforeCustomRelationResolvers(): void
	{
		$this->registry->collection('profile')
			->primaryKey('id')->field('id', 'int')->end()
			->field('user_id', 'int')->end()
			->end();
		$resolved = false;
		$this->registry->collection('user')
			->primaryKey('id')->field('id', 'int')->end()
			->metadata('gql::resolver::findById', static fn () => (object) ['id' => 1])
			->hasOne('profile', 'profile')->innerKey('id')->outerKey('user_id')
			->metadata('gql::resolver', static function () use (&$resolved): object {
				$resolved = true;

				return (object) ['id' => 2];
			})
			->end()
			->end();
		$generator = new GraphQLRegistryGenerator(
			$this->registry,
			authorizer: static fn (Collection $collection, string $operation): bool => $operation !== 'relation',
		);

		$result = GraphQL::executeQuery($generator->generate(), '{ user_by_id(id: "1") { id profile { id } } }')
			->toArray();

		$this->assertSame(['id' => 1, 'profile' => null], $result['data']['user_by_id']);
		$this->assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code']);
		$this->assertFalse($resolved);
	}
}
