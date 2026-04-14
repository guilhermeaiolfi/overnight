<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL\Support;

use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\HasManyRelation;
use PHPUnit\Framework\TestCase;

trait GraphQLTestFixtures
{
	protected function createUserCollection(Registry $registry): void
	{
		$registry->collection('user')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)->end()
			->field('email', 'string')->type('string')->nullable(true)->end()
			->end();
	}

	protected function createPostCollection(Registry $registry): void
	{
		$registry->collection('post')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('user_id', 'int')->type('int')->nullable(false)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->end();
	}

	protected function createUserWithPostsRelation(Registry $registry): void
	{
		$this->createPostCollection($registry);

		$userCollection = $registry->collection('user');
		$userCollection->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end();
		$userCollection->field('name', 'string')->type('string')->nullable(true)->end();
		$userCollection->relation('posts', HasManyRelation::class)
			->collection('post')
			->innerKey('user_id')
			->outerKey('id')
			->end();
	}

	protected function createFullSchema(Registry $registry): void
	{
		$registry->collection('comment')
			->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end()
			->field('post_id', 'int')->type('int')->nullable(false)->end()
			->field('body', 'string')->type('string')->nullable(true)->end()
			->field('author', 'string')->type('string')->nullable(true)->end()
			->end();

		$postCollection = $registry->collection('post');
		$postCollection->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end();
		$postCollection->field('user_id', 'int')->type('int')->nullable(false)->end();
		$postCollection->field('title', 'string')->type('string')->nullable(true)->end();
		$postCollection->field('status', 'string')->type('string')->nullable(true)->end();
		$postCollection->relation('comments', HasManyRelation::class)
			->collection('comment')
			->innerKey('post_id')
			->outerKey('id')
			->end();

		$userCollection = $registry->collection('user');
		$userCollection->field('id', 'int')->type('int')->primaryKey(true)->nullable(false)->end();
		$userCollection->field('name', 'string')->type('string')->nullable(true)->end();
		$userCollection->field('email', 'string')->type('string')->nullable(true)->end();
		$userCollection->relation('posts', HasManyRelation::class)
			->collection('post')
			->innerKey('user_id')
			->outerKey('id')
			->end();
	}

	protected function createFullDatabase(): SqliteTestDatabase
	{
		return new SqliteTestDatabase([
			'user' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'name' => 'TEXT', 'email' => 'TEXT'],
				'rows' => [
					['id' => 1, 'name' => 'John', 'email' => 'john@test.com'],
					['id' => 2, 'name' => 'Jane', 'email' => 'jane@test.com'],
					['id' => 3, 'name' => 'Bob', 'email' => 'bob@test.com'],
				],
			],
			'post' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'user_id' => 'INTEGER NOT NULL', 'title' => 'TEXT', 'status' => 'TEXT'],
				'rows' => [
					['id' => 1, 'user_id' => 1, 'title' => 'PHP Tips', 'status' => 'published'],
					['id' => 2, 'user_id' => 1, 'title' => 'Draft Post', 'status' => 'draft'],
					['id' => 3, 'user_id' => 2, 'title' => 'GraphQL Guide', 'status' => 'published'],
					['id' => 4, 'user_id' => 3, 'title' => 'Hello World', 'status' => 'published'],
				],
			],
			'comment' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'post_id' => 'INTEGER NOT NULL', 'body' => 'TEXT', 'author' => 'TEXT'],
				'rows' => [
					['id' => 1, 'post_id' => 1, 'body' => 'Great tips!', 'author' => 'Alice'],
					['id' => 2, 'post_id' => 1, 'body' => 'Very helpful', 'author' => 'Bob'],
					['id' => 3, 'post_id' => 3, 'body' => 'Nice guide', 'author' => 'John'],
					['id' => 4, 'post_id' => 4, 'body' => 'Welcome!', 'author' => 'Jane'],
					['id' => 5, 'post_id' => 4, 'body' => 'Good start', 'author' => 'Alice'],
				],
			],
		]);
	}

	protected function createTestDatabase(): SqliteTestDatabase
	{
		return new SqliteTestDatabase([
			'user' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'name' => 'TEXT', 'email' => 'TEXT'],
				'rows' => [
					['id' => 1, 'name' => 'John', 'email' => 'john@test.com'],
					['id' => 2, 'name' => 'Jane', 'email' => 'jane@test.com'],
				],
			],
			'post' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'user_id' => 'INTEGER NOT NULL', 'title' => 'TEXT', 'content' => 'TEXT'],
				'rows' => [
					['id' => 1, 'user_id' => 1, 'title' => 'Post 1', 'content' => 'Content 1'],
					['id' => 2, 'user_id' => 1, 'title' => 'Post 2', 'content' => 'Content 2'],
					['id' => 3, 'user_id' => 2, 'title' => 'Post 3', 'content' => 'Content 3'],
				],
			],
		]);
	}
}
