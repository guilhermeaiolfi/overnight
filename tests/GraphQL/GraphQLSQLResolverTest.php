<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use ON\DB\DatabaseInterface;
use ON\GraphQL\GraphQLRegistryGenerator;
use ON\ORM\Definition\Registry;
use PDO;
use PHPUnit\Framework\TestCase;

final class GraphQLSQLResolverTest extends TestCase
{
	private Registry $registry;
	private ?TestDatabaseMock $database = null;

	protected function setUp(): void
	{
		$this->registry = new Registry();
		$this->database = new TestDatabaseMock();
	}

	public function testExecuteQueryAndFetchRealData(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)->end()
			->field('email', 'string')->type('string')->nullable(true)->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry, null, $this->database);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userField = $queryType->getField('user');
		$resolver = $userField->resolveFn;

		$users = $resolver(null, [], null, null);

		$this->assertIsArray($users);
		$this->assertNotNull($users);
	}

	public function testExecuteQueryWithRelationsAndFetchData(): void
	{
		$this->registry->collection('post')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('user_id', 'int')->type('int')->nullable(false)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->end();

		$userCollection = $this->registry->collection('user');
		$userCollection->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end();
		$userCollection->field('name', 'string')->type('string')->nullable(true)->end();
		$userCollection->relation('posts')
			->collection('post')
			->innerKey('user_id')
			->outerKey('id')
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry, null, $this->database);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$userType = $queryType->getField('user')->getType()->getWrappedType();
		$postsField = $userType->getField('posts');
		$postsResolver = $postsField->resolveFn;

		$mockSource = new \stdClass();
		$mockSource->id = 1;
		$mockSource->name = 'John';

		$posts = $postsResolver($mockSource, [], null, null);

		$this->assertIsArray($posts);
		$this->assertCount(2, $posts);
		$this->assertSame('Post 1', $posts[0]->title);
	}

	public function testExecuteFindByIdQuery(): void
	{
		$this->registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)->end()
			->field('email', 'string')->type('string')->nullable(true)->end()
			->end();

		$generator = new GraphQLRegistryGenerator($this->registry, null, $this->database);
		$schema = $generator->generate();

		$queryType = $schema->getQueryType();
		$byIdField = $queryType->getField('user_by_id');
		$this->assertNotNull($byIdField);
	}
}

class TestDatabaseMock implements DatabaseInterface
{
	private array $users = [
		['id' => 1, 'name' => 'John', 'email' => 'john@test.com'],
		['id' => 2, 'name' => 'Jane', 'email' => 'jane@test.com'],
	];

	private array $posts = [
		['id' => 1, 'user_id' => 1, 'title' => 'Post 1', 'content' => 'Content 1'],
		['id' => 2, 'user_id' => 1, 'title' => 'Post 2', 'content' => 'Content 2'],
		['id' => 3, 'user_id' => 2, 'title' => 'Post 3', 'content' => 'Content 3'],
	];

	public function getRawUsers(): array
	{
		return $this->users;
	}

	public function getRawPosts(): array
	{
		return $this->posts;
	}

	public function getConnection(): mixed
	{
		return new class($this->users, $this->posts) {
			private array $users;
			private array $posts;

			public function __construct(array $users, array $posts)
			{
				$this->users = $users;
				$this->posts = $posts;
			}

			public function prepare(string $sql)
			{
				if (str_contains($sql, 'FROM users')) {
					return new TestStatementMock($this->users);
				}
				if (str_contains($sql, 'WHERE user_id')) {
					return new TestStatementMock($this->posts, 'user_id');
				}
				if (str_contains($sql, 'FROM post')) {
					return new TestStatementMock($this->posts);
				}
				return new TestStatementMock([]);
			}

			public function query(string $sql): array
			{
				if (str_contains($sql, 'FROM users')) {
					return array_map(fn($r) => (object) $r, $this->users);
				}
				if (str_contains($sql, 'FROM posts')) {
					return array_map(fn($r) => (object) $r, $this->posts);
				}
				return [];
			}

			public function lastInsertId(): string
			{
				return '3';
			}
		};
	}

	public function getResource(): mixed
	{
		return $this->getConnection();
	}

	public function setName(string $name): void
	{
	}

	public function getName(): string
	{
		return 'test';
	}

	public function wasCalled(): bool
	{
		return true;
	}
}

class TestStatementMock
{
	public function __construct(private array $rows, private ?string $filterColumn = null)
	{
	}

	public function execute(array $params = []): bool
	{
		if ($this->filterColumn !== null && !empty($params)) {
			$this->rows = array_filter($this->rows, fn($row) => $row[$this->filterColumn] == $params[0]);
		}
		return true;
	}

	public function fetchAll(int $mode = 2): array
	{
		return array_map(fn($r) => (object) $r, $this->rows);
	}

	public function fetch(int $mode = 2): ?object
	{
		$row = array_shift($this->rows);
		return $row ? (object) $row : null;
	}

	public function rowCount(): int
	{
		return count($this->rows);
	}
}