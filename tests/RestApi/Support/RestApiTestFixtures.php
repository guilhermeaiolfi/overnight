<?php

declare(strict_types=1);

namespace Tests\ON\RestApi\Support;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\ORM\Definition\Registry;
use ON\ORM\Typecast\CollectionTypecast;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\HandlerRegistry;
use ON\RestApi\Mapping\CollectionMapper;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Payload\MutationSpecUnserializer;
use ON\RestApi\Payload\PayloadNormalizer;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Query\QueryPlanner;
use ON\RestApi\Repository\ItemRepository;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\RestApiService;
use ON\RestApi\Serialize\CollectionSerializer;
use Psr\EventDispatcher\EventDispatcherInterface;

trait RestApiTestFixtures
{
	protected function createUserCollection(Registry $registry): void
	{
		$registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)->end()
			->field('email', 'string')->type('string')->nullable(true)->end()
			->field('password', 'string')->type('string')->hidden(true)->nullable(true)->end()
			->end();
	}

	protected function createPostCollection(Registry $registry): void
	{
		$registry->collection('post')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('user_id', 'int')->type('int')->nullable(false)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->field('content', 'text')->type('text')->nullable(true)->end()
			->field('status', 'string')->type('string')->nullable(true)->end()
			->field('created_at', 'datetime')->type('datetime')->nullable(true)->end()
			->end();
	}

	protected function createCommentCollection(Registry $registry): void
	{
		$registry->collection('comment')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('post_id', 'int')->type('int')->nullable(false)->end()
			->field('body', 'string')->type('string')->nullable(true)->end()
			->field('author', 'string')->type('string')->nullable(true)->end()
			->end();
	}

	protected function createTagCollection(Registry $registry): void
	{
		$registry->collection('tag')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)->end()
			->end();
	}

	protected function createProfileCollection(Registry $registry): void
	{
		$registry->collection('profile')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('displayName', 'string')->type('string')->column('display_name')->nullable(true)->end()
			->end();
	}

	protected function createFullSchema(Registry $registry): void
	{
		// Create tag collection first (no relations)
		$this->createTagCollection($registry);

		$registry->collection('post_tag')
			->field('post_id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('tag_id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->end();

		// Create comment collection (no relations of its own)
		$this->createCommentCollection($registry);

		// Create post collection with relations
		$postCollection = $registry->collection('post');
		$postCollection->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end();
		$postCollection->field('user_id', 'int')->type('int')->nullable(false)->end();
		$postCollection->field('title', 'string')->type('string')->nullable(true)->end();
		$postCollection->field('content', 'text')->type('text')->nullable(true)->end();
		$postCollection->field('status', 'string')->type('string')->nullable(true)->end();
		$postCollection->field('created_at', 'datetime')->type('datetime')->nullable(true)->end();

		// post hasMany comments: innerKey('id')->outerKey('post_id')
		$postCollection->hasMany('comments', 'comment')
			->innerKey('id')
			->outerKey('post_id')
			->end();

		// post belongsTo author (user): innerKey('user_id')->outerKey('id')
		$postCollection->belongsTo('author', 'user')
			->innerKey('user_id')
			->outerKey('id');

		// post M2M tags via post_tag
		$postCollection->relation('tags', M2MRelation::class)
			->collection('tag')
			->innerKey('id')
			->outerKey('id')
			->through('post_tag')
				->innerKey('post_id')
				->outerKey('tag_id')
				->end()
			->end();

		// Create user collection with hasMany posts
		$userCollection = $registry->collection('user');
		$userCollection->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end();
		$userCollection->field('name', 'string')->type('string')->nullable(true)->end();
		$userCollection->field('email', 'string')->type('string')->nullable(true)->end();
		$userCollection->field('password', 'string')->type('string')->hidden(true)->nullable(true)->end();

		// user hasMany posts: innerKey('id')->outerKey('user_id')
		$userCollection->hasMany('posts', 'post')
			->innerKey('id')
			->outerKey('user_id')
			->end();
	}

	protected function createTestDatabase(): CycleSqliteTestDatabase
	{
		return new CycleSqliteTestDatabase([
			'user' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'name' => 'TEXT',
					'email' => 'TEXT',
					'password' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'name' => 'John', 'email' => 'john@test.com', 'password' => 'secret1'],
					['id' => 2, 'name' => 'Jane', 'email' => 'jane@test.com', 'password' => 'secret2'],
				],
			],
			'post' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'user_id' => 'INTEGER NOT NULL',
					'title' => 'TEXT',
					'content' => 'TEXT',
					'status' => 'TEXT',
					'created_at' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'user_id' => 1, 'title' => 'PHP Tips', 'content' => 'Learn PHP', 'status' => 'published', 'created_at' => '2025-01-10 10:00:00'],
					['id' => 2, 'user_id' => 1, 'title' => 'Draft Post', 'content' => 'WIP', 'status' => 'draft', 'created_at' => '2025-02-11 10:00:00'],
					['id' => 3, 'user_id' => 2, 'title' => 'GraphQL Guide', 'content' => 'Learn GraphQL', 'status' => 'published', 'created_at' => '2026-01-12 10:00:00'],
				],
			],
		]);
	}

	protected function createFullDatabase(): CycleSqliteTestDatabase
	{
		return new CycleSqliteTestDatabase([
			'user' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'name' => 'TEXT',
					'email' => 'TEXT',
					'password' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'name' => 'John', 'email' => 'john@test.com', 'password' => 'secret1'],
					['id' => 2, 'name' => 'Jane', 'email' => 'jane@test.com', 'password' => 'secret2'],
					['id' => 3, 'name' => 'Bob', 'email' => 'bob@test.com', 'password' => 'secret3'],
				],
			],
			'post' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'user_id' => 'INTEGER NOT NULL',
					'title' => 'TEXT',
					'content' => 'TEXT',
					'status' => 'TEXT',
					'created_at' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'user_id' => 1, 'title' => 'PHP Tips', 'content' => 'Learn PHP', 'status' => 'published', 'created_at' => '2025-01-10 10:00:00'],
					['id' => 2, 'user_id' => 1, 'title' => 'Draft Post', 'content' => 'WIP', 'status' => 'draft', 'created_at' => '2025-02-11 10:00:00'],
					['id' => 3, 'user_id' => 2, 'title' => 'GraphQL Guide', 'content' => 'Learn GraphQL', 'status' => 'published', 'created_at' => '2026-01-12 10:00:00'],
				],
			],
			'comment' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'post_id' => 'INTEGER NOT NULL',
					'body' => 'TEXT',
					'author' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'post_id' => 1, 'body' => 'Great tips!', 'author' => 'Alice'],
					['id' => 2, 'post_id' => 1, 'body' => 'Very helpful', 'author' => 'Bob'],
					['id' => 3, 'post_id' => 3, 'body' => 'Nice guide', 'author' => 'John'],
				],
			],
			'tag' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'name' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'name' => 'PHP'],
					['id' => 2, 'name' => 'GraphQL'],
					['id' => 3, 'name' => 'REST'],
				],
			],
			'post_tag' => [
				'columns' => [
					'post_id' => 'INTEGER NOT NULL',
					'tag_id' => 'INTEGER NOT NULL',
				],
				'rows' => [
					['post_id' => 1, 'tag_id' => 1],
					['post_id' => 1, 'tag_id' => 2],
					['post_id' => 3, 'tag_id' => 2],
					['post_id' => 3, 'tag_id' => 3],
				],
			],
		]);
	}

	protected function createProfileDatabase(): CycleSqliteTestDatabase
	{
		return new CycleSqliteTestDatabase([
			'profile' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'display_name' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'display_name' => 'Alice'],
					['id' => 2, 'display_name' => 'Bob'],
				],
			],
		]);
	}

	protected function createItems(Registry $registry, CycleSqliteTestDatabase $db): ItemRepository
	{
		return new ItemRepository(
			$registry,
			$db->database(),
			new CollectionMapper(new CollectionTypecast()),
		);
	}

	protected function createHandlerFactory(ItemRepositoryInterface $items): HandlerFactory
	{
		return new HandlerFactory(
			HandlerRegistry::defaults(),
			$items,
			new SqlQuerySpecCompiler($items->getDatabase(), 100, 1000)
		);
	}

	protected function createQueryPlanner(Registry $registry, CycleSqliteTestDatabase $db): QueryPlanner
	{
		$items = $this->createItems($registry, $db);
		$handlers = $this->createHandlerFactory($items);

		return new QueryPlanner(
			$items,
			$handlers,
			new SqlQuerySpecCompiler($db->database(), 100, 1000)
		);
	}

	protected function createRestApiService(
		Registry $registry,
		ItemRepositoryInterface $items,
		?EventDispatcherInterface $eventDispatcher = null
	): RestApiService {
		$handlers = $this->createHandlerFactory($items);
		$queryPlanner = new QueryPlanner(
			$items,
			$handlers,
			new SqlQuerySpecCompiler($items->getDatabase(), 100, 1000)
		);

		return new RestApiService(
			$registry,
			$items,
			$queryPlanner,
			$eventDispatcher,
			$handlers,
			new CollectionSerializer(),
			new FileUploadEventEmitter($registry, $eventDispatcher),
		);
	}

	protected function createMutationBuilder(
		Registry $registry,
		ItemRepositoryInterface $items,
		?EventDispatcherInterface $eventDispatcher = null,
	): DirectusMutationBuilder {
		$handlers = $this->createHandlerFactory($items);

		return new DirectusMutationBuilder(
			$registry,
			$items,
			new PayloadNormalizer($handlers, $registry),
			new MutationSpecUnserializer($registry),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @param 'create'|'update'|'upsert' $mode
	 */
	protected function buildMutationSpec(
		Registry $registry,
		ItemRepositoryInterface $items,
		CollectionInterface|string $collection,
		array $input,
		string $mode = 'create',
		PrimaryKeyValue|string|null $id = null,
		array $files = [],
		bool $partial = false,
		?EventDispatcherInterface $eventDispatcher = null,
		bool $unserializeWire = true,
	): MutationSpec {
		$collection = is_string($collection) ? $registry->getCollection($collection) : $collection;

		return $this->createMutationBuilder($registry, $items)->build(
			$collection,
			$input,
			$mode,
			$id,
			$files,
			$unserializeWire,
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @param 'create'|'update'|'upsert' $mode
	 */
	protected function m(
		Registry $registry,
		ItemRepositoryInterface $items,
		CollectionInterface|string $collection,
		array $input,
		string $mode = 'create',
		PrimaryKeyValue|string|null $id = null,
		array $files = [],
		bool $partial = false,
	): MutationSpec {
		return $this->buildMutationSpec($registry, $items, $collection, $input, $mode, $id, $files, $partial);
	}
}
