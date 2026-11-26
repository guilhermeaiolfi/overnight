<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL;

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\GraphQL\Error\GraphQLUserError;
use ON\GraphQL\GraphQLRegistryGenerator;
use ON\GraphQL\Middleware\GraphQLMiddleware;
use ON\GraphQL\Resolver\SqlResolver;
use ON\Validation\CollectionValidator;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\ON\GraphQL\Support\GraphQLTestFixtures;
use Tests\ON\GraphQL\Support\SqliteTestDatabase;

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

		$generator = new GraphQLRegistryGenerator($this->registry, new SqlResolver($this->registry, $database));
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userField = $queryType->getField('user');
		$resolver = $userField->resolveFn;

		$users = $resolver(null, [], null, null);

		$this->assertIsArray($users);
		$this->assertArrayHasKey('items', $users);
		$this->assertArrayHasKey('totalCount', $users);
	}

	public function testListProjectsFieldsWhenACustomRelationDoesNotDeclareKeys(): void
	{
		$this->createUserCollection($this->registry);
		$this->createPostCollection($this->registry);
		$user = $this->registry->getCollection('user');
		$user->hasMany('posts', 'post')
			->metadata('gql::resolver', static fn (): array => [])
			->end();

		$resolver = new SqlResolver($this->registry, $this->createTestDatabase());
		$result = $resolver->resolveCollection($user);

		$this->assertSame(['John', 'Jane'], array_column($result['items'], 'name'));
	}

	public function testExecuteGraphQLQueryWithRelations(): void
	{
		$this->createUserWithPostsRelation($this->registry);
		$database = $this->createTestDatabase();

		$generator = new GraphQLRegistryGenerator($this->registry, new SqlResolver($this->registry, $database));
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

		$generator = new GraphQLRegistryGenerator($this->registry, new SqlResolver($this->registry, $database));
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

		$generator = new GraphQLRegistryGenerator($this->registry, new SqlResolver($this->registry, $database));
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

		$generator = new GraphQLRegistryGenerator($this->registry, new SqlResolver($this->registry, $database));
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

		$generator = new GraphQLRegistryGenerator($this->registry, $resolver);
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
		$this->registry->getCollection('user')->fields->get('email')->validation('required|email');
		$database = $this->createTestDatabase();
		$resolver = new SqlResolver($this->registry, $database);

		$generator = new GraphQLRegistryGenerator($this->registry, $resolver);
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

	public function testSingleAndManyRelationsCanShareTheSameForeignKey(): void
	{
		$this->createFullSchema($this->registry);
		$this->registry->getCollection('user')->hasOne('featuredPost', 'post')
			->innerKey('id')->outerKey('user_id')->end();

		$resolver = new SqlResolver($this->registry, $this->createFullDatabase());
		$schema = (new GraphQLRegistryGenerator($this->registry, $resolver))->generate();

		$result = GraphQL::executeQuery($schema, '{ user_by_id(id: "1") { featuredPost { id } posts { id } } }')
			->toArray();

		$this->assertArrayNotHasKey('errors', $result);
		$this->assertSame(1, $result['data']['user_by_id']['featuredPost']['id']);
		$this->assertSame([1, 2], array_column($result['data']['user_by_id']['posts'], 'id'));
	}

	public function testNestedCreateRejectsDerivedRelationsWithoutChangingEitherTable(): void
	{
		$this->createUserWithPostsRelation($this->registry);
		$user = $this->registry->getCollection('user');
		$user->relation('latest_post', FirstOfManyRelation::class)
			->collection('post')
			->innerKey('id')
			->outerKey('user_id')
			->orderBy(['id' => 'desc'])
			->end();
		$database = $this->createTestDatabase();
		$resolver = new SqlResolver($this->registry, $database);

		try {
			$resolver->resolveNestedCreate($user, ['name' => 'Alex'], ['latest_post' => ['title' => 'Post 4']]);
			$this->fail('The derived relation must reject the nested create.');
		} catch (GraphQLUserError $error) {
			$this->assertSame('INVALID_INPUT', $error->getExtensions()['code']);
		}

		$connection = $database->getConnection();
		$this->assertSame(2, (int) $connection->query('SELECT COUNT(*) FROM user')->fetchColumn());
		$this->assertSame(3, (int) $connection->query('SELECT COUNT(*) FROM post')->fetchColumn());
	}

	public function testNestedCreateRollsBackWhenTheDeclaredSourceKeyIsNull(): void
	{
		$database = $this->createDocumentWithNotes();
		$resolver = new SqlResolver($this->registry, $database);

		try {
			$resolver->resolveNestedCreate(
				$this->registry->getCollection('document'),
				['title' => 'Document'],
				['notes' => [['text' => 'Note']]],
			);
			$this->fail('The note requires the declared parent relation key.');
		} catch (GraphQLUserError $error) {
			$this->assertSame('INVALID_INPUT', $error->getExtensions()['code']);
		}

		$this->assertSame(0, (int) $database->getConnection()->query('SELECT COUNT(*) FROM document')->fetchColumn());
		$this->assertSame(0, (int) $database->getConnection()->query('SELECT COUNT(*) FROM note')->fetchColumn());
	}

	public function testNestedCreateReturnsParentWithHiddenIdentifierAndAnAlternateRelationKey(): void
	{
		$database = $this->createDocumentWithNotes();
		$resolver = new SqlResolver($this->registry, $database);

		$parent = $resolver->resolveNestedCreate(
			$this->registry->getCollection('document'),
			['title' => 'Document', 'external_id' => 41],
			['notes' => [['text' => 'Note']]],
		);

		$this->assertSame(['title' => 'Document', 'external_id' => 41], (array) $parent);
		$this->assertEquals(41, $database->getConnection()->query('SELECT document_key FROM note')->fetchColumn());
	}

	public function testNestedCreateRollsBackIfTheParentDisappearsBeforeItCanBeReturned(): void
	{
		$database = $this->createDocumentWithNotes();
		$connection = $database->getConnection();
		$connection->exec('CREATE TRIGGER remove_document AFTER INSERT ON note BEGIN
			DELETE FROM document WHERE external_id = NEW.document_key;
		END');
		$resolver = new SqlResolver($this->registry, $database);

		try {
			$resolver->resolveNestedCreate(
				$this->registry->getCollection('document'),
				['title' => 'Document', 'external_id' => 41],
				['notes' => [['text' => 'Note']]],
			);
			$this->fail('A missing parent must reject the nested create.');
		} catch (GraphQLUserError $error) {
			$this->assertSame('DATABASE_ERROR', $error->getExtensions()['code']);
		}

		$this->assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM note')->fetchColumn());
	}

	public function testNestedCreatePreparesEffectiveChildInputWithinTheTransaction(): void
	{
		$database = $this->createDocumentWithNotes();
		$connection = $database->getConnection();
		$resolver = new SqlResolver($this->registry, $database);

		try {
			$resolver->resolveNestedCreateWithChildPreparation(
				$this->registry->getCollection('document'),
				['title' => 'Document', 'external_id' => 41],
				['notes' => [['text' => 'Note']]],
				function (string $name, int $index, RelationInterface $relation, array $input) use ($connection): void {
					$this->assertSame('notes', $name);
					$this->assertSame(0, $index);
					$this->assertSame($name, $relation->getName());
					$this->assertSame(['text' => 'Note', 'document_external_id' => 41], $input);
					$this->assertTrue($connection->inTransaction());

					throw new GraphQLUserError('Child creation was denied.', 'FORBIDDEN');
				},
			);
			$this->fail('A denied child must reject the nested create.');
		} catch (GraphQLUserError $error) {
			$this->assertSame('FORBIDDEN', $error->getExtensions()['code']);
		}

		$this->assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM document')->fetchColumn());
		$this->assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM note')->fetchColumn());
	}

	public function testBatchReadsSeeRelationChangesMadeByEarlierOperations(): void
	{
		$this->createUserWithPostsRelation($this->registry);
		$this->registry->getCollection('post')->belongsTo('author', 'user')->innerKey('user_id')->outerKey('id')->end();

		$database = $this->createTestDatabase();
		$database->getConnection()->exec("INSERT INTO post (id, user_id, title) VALUES (4, 3, 'Imported post')");
		$resolver = new SqlResolver($this->registry, $database);
		$middleware = new GraphQLMiddleware((new GraphQLRegistryGenerator($this->registry, $resolver))->generate());

		$userPosts = '{ user_by_id(id: "1") { posts { title } } }';
		$postAuthor = '{ post_by_id(id: "4") { author { name } } }';
		$queries = [
			$userPosts,
			'mutation { create_post(input: { user_id: 1, title: "New post" }) { id } }',
			$userPosts,
			'mutation { update_post(id: "5", input: { title: "Renamed post" }) { id } }',
			$userPosts,
			'mutation { delete_post(id: "5") { id } }',
			$userPosts,
			$postAuthor,
			'mutation { create_user(input: { name: "Alex", posts: [{ title: "First post" }] }) { id } }',
			$postAuthor,
		];

		$body = new Stream('php://temp', 'w+');
		$body->write(json_encode(array_map(static fn (string $query) => ['query' => $query], $queries), JSON_THROW_ON_ERROR));
		$body->rewind();
		$request = (new ServerRequest())->withMethod('POST')
			->withHeader('Content-Type', 'application/json')
			->withBody($body);
		$response = $middleware->process($request, $this->createStub(RequestHandlerInterface::class));
		$this->assertSame(200, $response->getStatusCode());
		$results = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
		foreach ($results as $result) {
			$this->assertArrayNotHasKey('errors', $result, json_encode($result, JSON_THROW_ON_ERROR));
		}
		$posts = static fn (int $operation): array => array_column($results[$operation]['data']['user_by_id']['posts'], 'title');
		$this->assertSame(['Post 1', 'Post 2'], $posts(0));
		$this->assertSame(['Post 1', 'Post 2', 'New post'], $posts(2));
		$this->assertSame(['Post 1', 'Post 2', 'Renamed post'], $posts(4));
		$this->assertSame(['Post 1', 'Post 2'], $posts(6));
		$this->assertNull($results[7]['data']['post_by_id']['author']);
		$this->assertSame('Alex', $results[9]['data']['post_by_id']['author']['name']);
	}

	public function testNestedCreateRollsBackWhenAChildWriteFailsInsideAnExistingTransaction(): void
	{
		$this->createUserWithPostsRelation($this->registry);
		$database = $this->createTestDatabase();
		$connection = $database->getConnection();
		$connection->exec('CREATE UNIQUE INDEX post_title ON post (title)');
		$connection->beginTransaction();
		$connection->exec("INSERT INTO user (name) VALUES ('Other change')");

		try {
			$resolver = new SqlResolver($this->registry, $database);
			$resolver->resolveNestedCreate(
				$this->registry->getCollection('user'),
				['name' => 'New user'],
				['posts' => [['title' => 'New post'], ['title' => 'Post 1']]],
			);
			$this->fail('The duplicate title must reject the nested write.');
		} catch (GraphQLUserError $error) {
			$this->assertSame('DUPLICATE', $error->getExtensions()['code']);
			$this->assertTrue($connection->inTransaction());
			$this->assertSame(
				['John', 'Jane', 'Other change'],
				$connection->query('SELECT name FROM user ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
			);
			$this->assertSame(3, (int) $connection->query('SELECT COUNT(*) FROM post')->fetchColumn());
		} finally {
			$connection->rollBack();
		}
	}

	public function testNestedCreateValidatesChildFieldsBeforeSavingTheParent(): void
	{
		$this->createUserWithPostsRelation($this->registry);
		$post = $this->registry->getCollection('post');
		$post->fields->get('title')->validation('required');
		$post->fields->get('user_id')->validation('required');

		$database = $this->createTestDatabase();
		$resolver = new SqlResolver($this->registry, $database);
		$schema = (new GraphQLRegistryGenerator($this->registry, $resolver))->generate();
		$query = 'mutation { create_user(input: { name: "Alex", posts: [{}] }) { id } }';

		$result = GraphQL::executeQuery($schema, $query)->toArray();
		$this->assertSame('VALIDATION_ERROR', $result['errors'][0]['extensions']['code']);
		$this->assertSame(['posts.0.title'], array_keys($result['errors'][0]['extensions']['validationErrors']));
		$this->assertSame(2, (int) $database->getConnection()->query('SELECT COUNT(*) FROM user')->fetchColumn());

		$query = 'mutation { create_user(input: { name: "Alex", posts: [{ title: "New post" }] }) { posts { title } } }';
		$result = GraphQL::executeQuery($schema, $query)->toArray();
		$this->assertArrayNotHasKey('errors', $result);
		$this->assertSame([['title' => 'New post']], $result['data']['create_user']['posts']);
	}

	public function testCreateDuplicateReturnsStructuredError(): void
	{
		// Create a collection with a unique constraint
		$this->registry->collection('category')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(false)->end()
			->end();

		$database = new SqliteTestDatabase([
			'category' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'name' => 'TEXT NOT NULL UNIQUE'],
				'rows' => [
					['id' => 1, 'name' => 'Existing'],
				],
			],
		]);

		$resolver = new SqlResolver($this->registry, $database);
		$generator = new GraphQLRegistryGenerator($this->registry, $resolver);
		$schema = $generator->generate();

		$query = '
		mutation {
			create_category(input: { name: "Existing" }) {
				id
				name
			}
		}';

		$result = GraphQL::executeQuery($schema, $query);
		$data = $result->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);

		$this->assertArrayHasKey('errors', $data);
		$this->assertNotEmpty($data['errors']);

		$error = $data['errors'][0];
		$this->assertArrayHasKey('extensions', $error);
		$this->assertSame('DUPLICATE', $error['extensions']['code']);
	}

	public function testValidationRejectsInvalidInput(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)
				->validation('required|min:3')
				->end()
			->field('email', 'string')->type('string')->nullable(true)
				->validation('required|email')
				->end()
			->end();

		$database = $this->createTestDatabase();
		$resolver = new SqlResolver($this->registry, $database);
		$generator = new GraphQLRegistryGenerator($this->registry, $resolver);
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
		$data = $result->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);

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

	public function testValidationUsesFieldMessages(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)
				->validation('required|min:3', [
					'min' => 'Name must be at least :min characters.',
				])
				->end()
			->end();

		$database = $this->createTestDatabase();
		$resolver = new SqlResolver($this->registry, $database);
		$generator = new GraphQLRegistryGenerator(
			$this->registry,
			$resolver,
			null,
			new CollectionValidator([
				'rule.required' => 'The :attribute field is required.',
			]),
		);
		$schema = $generator->generate();

		$query = '
		mutation {
			create_user(input: { name: "Jo" }) {
				id
				name
			}
		}';

		$result = GraphQL::executeQuery($schema, $query);
		$data = $result->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);

		$this->assertArrayHasKey('errors', $data);
		$this->assertSame(
			['Name must be at least 3 characters.'],
			$data['errors'][0]['extensions']['validationErrors']['name']
		);
	}

	public function testValidationPassesWithValidInput(): void
	{
		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)
				->validation('required|min:3')
				->end()
			->field('email', 'string')->type('string')->nullable(true)
				->validation('required|email')
				->end()
			->end();

		$database = $this->createTestDatabase();
		$resolver = new SqlResolver($this->registry, $database);
		$generator = new GraphQLRegistryGenerator($this->registry, $resolver);
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

	private function createDocumentWithNotes(): SqliteTestDatabase
	{
		$this->registry->collection('document')
			->primaryKey('id')->field('id', 'int')->hidden(true)->end()
			->field('title', 'string')->end()
			->field('external_id', 'int')->nullable(true)->end()
			->hasMany('notes', 'note')->innerKey('external_id')->outerKey('document_external_id')->end()
			->end();
		$this->registry->collection('note')
			->primaryKey('id')->field('id', 'int')->end()
			->field('text', 'string')->end()
			->field('document_external_id', 'int')->column('document_key')->nullable(true)->end()
			->end();

		return new SqliteTestDatabase([
			'document' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'title' => 'TEXT', 'external_id' => 'INTEGER'],
				'rows' => [],
			],
			'note' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'text' => 'TEXT', 'document_key' => 'INTEGER'],
				'rows' => [],
			],
		]);
	}
}
