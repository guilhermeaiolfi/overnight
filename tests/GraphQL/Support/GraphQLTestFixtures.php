<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL\Support;

use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\HasManyRelation;

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

	protected function createTestDatabase(): InMemoryDatabase
	{
		return new InMemoryDatabase([
			'user' => [
				['id' => 1, 'name' => 'John', 'email' => 'john@test.com'],
				['id' => 2, 'name' => 'Jane', 'email' => 'jane@test.com'],
			],
			'post' => [
				['id' => 1, 'user_id' => 1, 'title' => 'Post 1', 'content' => 'Content 1'],
				['id' => 2, 'user_id' => 1, 'title' => 'Post 2', 'content' => 'Content 2'],
				['id' => 3, 'user_id' => 2, 'title' => 'Post 3', 'content' => 'Content 3'],
			],
		]);
	}
}
