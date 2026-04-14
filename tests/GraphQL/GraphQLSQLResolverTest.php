<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL;

use GraphQL\GraphQL;
use ON\GraphQL\GraphQLRegistryGenerator;
use ON\GraphQL\Resolver\SqlResolver;
use ON\ORM\Definition\Registry;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\ON\GraphQL\Support\GraphQLTestFixtures;

#[RequiresPhpExtension('pdo_sqlite')]
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

		$generator = new GraphQLRegistryGenerator($this->registry, null, new SqlResolver($this->registry, $database));
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

		$generator = new GraphQLRegistryGenerator($this->registry, null, new SqlResolver($this->registry, $database));
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

	public function testExecuteNestedQueryWithFilters(): void
	{
		$this->createFullSchema($this->registry);
		$database = $this->createFullDatabase();

		$generator = new GraphQLRegistryGenerator($this->registry, null, new SqlResolver($this->registry, $database));
		$schema = $generator->generate();

		// Query users filtered by name, with nested posts and comments
		$query = '
		{
			user(name: "John") {
				items {
					id
					name
					posts {
						id
						title
						comments {
							id
							body
							author
						}
					}
				}
				totalCount
			}
		}';

		$result = GraphQL::executeQuery($schema, $query);
		$data = $result->toArray();

		$this->assertArrayNotHasKey('errors', $data);

		$connection = $data['data']['user'];
		$this->assertSame(1, $connection['totalCount']);

		$users = $connection['items'];
		$this->assertCount(1, $users);

		$john = $users[0];
		$this->assertSame(1, $john['id']);
		$this->assertSame('John', $john['name']);

		// John has 2 posts
		$this->assertCount(2, $john['posts']);

		// First post (PHP Tips) has 2 comments
		$phpTips = $john['posts'][0];
		$this->assertSame('PHP Tips', $phpTips['title']);
		$this->assertCount(2, $phpTips['comments']);
		$this->assertSame('Great tips!', $phpTips['comments'][0]['body']);
		$this->assertSame('Very helpful', $phpTips['comments'][1]['body']);

		// Second post (Draft Post) has 0 comments
		$draft = $john['posts'][1];
		$this->assertSame('Draft Post', $draft['title']);
		$this->assertCount(0, $draft['comments']);
	}

	public function testExecuteQueryWithLikeFilter(): void
	{
		$this->createFullSchema($this->registry);
		$database = $this->createFullDatabase();

		$generator = new GraphQLRegistryGenerator($this->registry, null, new SqlResolver($this->registry, $database));
		$schema = $generator->generate();

		// Filter posts by title containing "Post" using LIKE
		$query = '{ post(title: "%Guide%") { items { id title } totalCount } }';
		$result = GraphQL::executeQuery($schema, $query);
		$data = $result->toArray();

		$this->assertArrayNotHasKey('errors', $data);

		$connection = $data['data']['post'];
		$this->assertSame(1, $connection['totalCount']);
		$this->assertCount(1, $connection['items']);
		$this->assertSame('GraphQL Guide', $connection['items'][0]['title']);
	}

	public function testExecuteFindByIdQuery(): void
	{
		$this->createUserCollection($this->registry);
		$database = $this->createTestDatabase();

		$generator = new GraphQLRegistryGenerator($this->registry, null, new SqlResolver($this->registry, $database));
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$byIdField = $queryType->getField('user_by_id');
		$this->assertNotNull($byIdField);
	}

	public function testNestedCreateMutationCreatesChildren(): void
	{
		$this->createUserWithPostsRelation($this->registry);
		$database = $this->createTestDatabase();
		$resolver = new SqlResolver($this->registry, $database);

		$generator = new GraphQLRegistryGenerator($this->registry, null, $resolver);
		$schema = $generator->generate();

		// Create user with nested posts
		$mutation = '
		mutation {
			create_user(input: {
				name: "Alice",
				posts: [
					{ title: "First Post" },
					{ title: "Second Post" }
				]
			}) {
				id
				name
			}
		}';

		$result = GraphQL::executeQuery($schema, $mutation);
		$data = $result->toArray();

		$this->assertArrayNotHasKey('errors', $data);
		$alice = $data['data']['create_user'];
		$this->assertSame('Alice', $alice['name']);

		// Now query Alice with her posts to verify children were created
		$query = '
		{
			user(name: "Alice") {
				items {
					id
					name
					posts { id title }
				}
				totalCount
			}
		}';

		$result = GraphQL::executeQuery($schema, $query);
		$data = $result->toArray();

		$this->assertArrayNotHasKey('errors', $data);
		$this->assertSame(1, $data['data']['user']['totalCount']);

		$aliceData = $data['data']['user']['items'][0];
		$this->assertSame('Alice', $aliceData['name']);
		$this->assertCount(2, $aliceData['posts']);
		$this->assertSame('First Post', $aliceData['posts'][0]['title']);
		$this->assertSame('Second Post', $aliceData['posts'][1]['title']);
	}

	public function testUpdateMutation(): void
	{
		$this->createUserCollection($this->registry);
		$database = $this->createTestDatabase();
		$resolver = new SqlResolver($this->registry, $database);

		$generator = new GraphQLRegistryGenerator($this->registry, null, $resolver);
		$schema = $generator->generate();

		// Update John's name
		$mutation = '
		mutation {
			update_user(id: "1", input: { name: "Johnny" }) {
				id
				name
				email
			}
		}';

		$result = GraphQL::executeQuery($schema, $mutation);
		$data = $result->toArray();

		$this->assertArrayNotHasKey('errors', $data);
		$user = $data['data']['update_user'];
		$this->assertSame(1, $user['id']);
		$this->assertSame('Johnny', $user['name']);
		$this->assertSame('john@test.com', $user['email']); // unchanged

		// Verify via query
		$query = '{ user_by_id(id: "1") { id name email } }';
		$result = GraphQL::executeQuery($schema, $query);
		$data = $result->toArray();

		$this->assertArrayNotHasKey('errors', $data);
		$this->assertSame('Johnny', $data['data']['user_by_id']['name']);
		$this->assertSame('john@test.com', $data['data']['user_by_id']['email']);
	}

	public function testCreateDuplicateReturnsStructuredError(): void
	{
		// Create a collection with a unique constraint
		$this->registry->collection('category')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(false)->end()
			->end();

		$database = new \Tests\ON\GraphQL\Support\SqliteTestDatabase([
			'category' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'name' => 'TEXT NOT NULL UNIQUE'],
				'rows' => [
					['id' => 1, 'name' => 'Existing'],
				],
			],
		]);

		$resolver = new SqlResolver($this->registry, $database);
		$generator = new GraphQLRegistryGenerator($this->registry, null, $resolver);
		$schema = $generator->generate();

		$query = '
		mutation {
			create_category(input: { name: "Existing" }) {
				id
				name
			}
		}';

		$result = GraphQL::executeQuery($schema, $query);
		$data = $result->toArray(\GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE);

		$this->assertArrayHasKey('errors', $data);
		$this->assertNotEmpty($data['errors']);

		$error = $data['errors'][0];
		$this->assertArrayHasKey('extensions', $error);
		$this->assertSame('DUPLICATE', $error['extensions']['code']);
	}

	public function testValidationRejectsInvalidInput(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)
				->validation('required|min:3')
				->end()
			->field('email', 'string')->type('string')->nullable(true)
				->validation('required|email')
				->end()
			->end();

		$database = $this->createTestDatabase();
		$resolver = new SqlResolver($this->registry, $database);
		$generator = new GraphQLRegistryGenerator($this->registry, null, $resolver);
		$schema = $generator->generate();

		// Try to create with invalid email and short name
		$query = '
		mutation {
			create_user(input: { name: "AB", email: "not-an-email" }) {
				id
				name
			}
		}';

		$result = GraphQL::executeQuery($schema, $query);
		$data = $result->toArray(\GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE);

		$this->assertArrayHasKey('errors', $data);
		$this->assertNotEmpty($data['errors']);

		$error = $data['errors'][0];
		$this->assertArrayHasKey('extensions', $error);
		$this->assertSame('VALIDATION_ERROR', $error['extensions']['code']);
		$this->assertNotNull($error['extensions']['field']);

		// All validation errors should be included
		$this->assertArrayHasKey('validationErrors', $error['extensions']);
		$validationErrors = $error['extensions']['validationErrors'];
		$this->assertArrayHasKey('name', $validationErrors);
		$this->assertArrayHasKey('email', $validationErrors);
	}

	public function testValidationPassesWithValidInput(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)
				->validation('required|min:3')
				->end()
			->field('email', 'string')->type('string')->nullable(true)
				->validation('required|email')
				->end()
			->end();

		$database = $this->createTestDatabase();
		$resolver = new SqlResolver($this->registry, $database);
		$generator = new GraphQLRegistryGenerator($this->registry, null, $resolver);
		$schema = $generator->generate();

		$query = '
		mutation {
			create_user(input: { name: "Alice", email: "alice@test.com" }) {
				id
				name
				email
			}
		}';

		$result = GraphQL::executeQuery($schema, $query);
		$data = $result->toArray();

		$this->assertArrayNotHasKey('errors', $data);
		$this->assertSame('Alice', $data['data']['create_user']['name']);
		$this->assertSame('alice@test.com', $data['data']['create_user']['email']);
	}
}
