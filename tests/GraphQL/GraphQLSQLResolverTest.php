<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL;

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\GraphQL;
use ON\GraphQL\GraphQLRegistryGenerator;
use ON\ORM\Definition\Registry;
use PHPUnit\Framework\TestCase;
use Tests\ON\GraphQL\Support\GraphQLTestFixtures;

final class GraphQLSQLResolverTest extends TestCase
{
	use GraphQLTestFixtures;

	private Registry $registry;

	protected function setUp(): void
	{
		$this->registry = new Registry();
	}

	public function testExecuteQueryAndFetchRealData(): void
	{
		$this->createUserCollection($this->registry);
		$database = $this->createTestDatabase();

		$generator = new GraphQLRegistryGenerator($this->registry, null, $database);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userField = $queryType->getField('user');
		$resolver = $userField->resolveFn;

		$users = $resolver(null, [], null, null);

		$this->assertIsArray($users);
		$this->assertArrayHasKey('items', $users);
		$this->assertArrayHasKey('totalCount', $users);
	}

	public function testExecuteGraphQLQueryWithRelations(): void
	{
		$this->createUserWithPostsRelation($this->registry);
		$database = $this->createTestDatabase();

		$generator = new GraphQLRegistryGenerator($this->registry, null, $database);
		$schema = $generator->generate();

		$query = '{ user { items { id name posts { id title } } totalCount } }';
		$result = GraphQL::executeQuery($schema, $query);
		$data = $result->toArray();

		$this->assertArrayNotHasKey('errors', $data);
		$this->assertArrayHasKey('data', $data);
		$this->assertArrayHasKey('user', $data['data']);

		$userConnection = $data['data']['user'];
		$this->assertArrayHasKey('items', $userConnection);
		$this->assertArrayHasKey('totalCount', $userConnection);
		$this->assertSame(2, $userConnection['totalCount']);

		$users = $userConnection['items'];
		$this->assertCount(2, $users);

		// John (id=1) should have 2 posts
		$john = $users[0];
		$this->assertSame(1, $john['id']);
		$this->assertSame('John', $john['name']);
		$this->assertCount(2, $john['posts']);
		$this->assertSame('Post 1', $john['posts'][0]['title']);
		$this->assertSame('Post 2', $john['posts'][1]['title']);

		// Jane (id=2) should have 1 post
		$jane = $users[1];
		$this->assertSame(2, $jane['id']);
		$this->assertSame('Jane', $jane['name']);
		$this->assertNotNull($jane['posts']);
		$this->assertCount(1, $jane['posts']);
		$this->assertSame('Post 3', $jane['posts'][0]['title']);
	}

	public function testExecuteFindByIdQuery(): void
	{
		$this->createUserCollection($this->registry);
		$database = $this->createTestDatabase();

		$generator = new GraphQLRegistryGenerator($this->registry, null, $database);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$byIdField = $queryType->getField('user_by_id');
		$this->assertNotNull($byIdField);
	}
}
