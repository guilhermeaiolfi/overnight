<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Registry;
use ON\RestApi\FieldSelector;
use ON\RestApi\Resolver\Sql\Loader\AliasRegistry;
use ON\RestApi\Resolver\Sql\Loader\HasManyLoader;
use ON\RestApi\Resolver\Sql\Loader\LoaderRegistry;
use ON\RestApi\Resolver\Sql\Loader\ManyToManyLoader;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class LoaderRegistryTest extends TestCase
{
	use RestApiTestFixtures;

	public function testDefaultRegistryResolvesRelationKinds(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);

		$loaders = LoaderRegistry::defaults();
		$post = $registry->getCollection('post');
		$user = $registry->getCollection('user');

		$this->assertSame(
			HasManyLoader::class,
			$loaders->resolve($user, 'posts', $user->relations->get('posts'))
		);
		$this->assertSame(
			ManyToManyLoader::class,
			$loaders->resolve($post, 'tags', $post->relations->get('tags'))
		);
	}

	public function testDuplicateRelationRegistrationFailsUnlessReplaced(): void
	{
		$loaders = LoaderRegistry::defaults();
		$loaders->relation('post', 'tags', ManyToManyLoader::class);

		$this->expectException(\LogicException::class);
		$loaders->relation('post', 'tags', ManyToManyLoader::class);
	}

	public function testRelationRegistrationCanBeReplacedExplicitly(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$post = $registry->getCollection('post');

		$loaders = LoaderRegistry::defaults();
		$loaders->relation('post', 'tags', HasManyLoader::class);
		$loaders->replaceRelation('post', 'tags', ManyToManyLoader::class);

		$this->assertSame(
			ManyToManyLoader::class,
			$loaders->resolve($post, 'tags', $post->relations->get('tags'))
		);
	}

	public function testFieldSelectorSeparatesRequestedFieldsFromInternalPrimaryKey(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);

		$fields = (new FieldSelector())->parse(
			$registry->getCollection('user'),
			'name,posts.tags.name'
		);

		$this->assertSame(['name'], array_values($fields['requestedFields']));
		$this->assertContains('id', $fields['fields']);
		$this->assertSame([], array_values($fields['relations']['posts']['requestedFields']));
		$this->assertContains('id', $fields['relations']['posts']['fields']);
		$this->assertSame(['name'], array_values($fields['relations']['posts']['relations']['tags']['requestedFields']));
	}

	public function testAliasRegistryCreatesReadableUniqueAliases(): void
	{
		$aliases = new AliasRegistry();

		$this->assertSame('__on_tags_parent_key', $aliases->alias('__on_tags_parent_key'));
		$this->assertSame('__on_tags_parent_key_1', $aliases->alias('__on_tags_parent_key'));
		$this->assertSame('tags_parent_key', $aliases->alias('tags.parent-key'));
	}
}
