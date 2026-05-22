<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Registry;
use ON\RestApi\Query\Node\FieldSelection;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Handler\AliasRegistry;
use ON\RestApi\Handler\HandlerRegistry;
use ON\RestApi\Handler\HasManyHandler;
use ON\RestApi\Handler\ManyToManyHandler;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class LoaderRegistryTest extends TestCase
{
	use RestApiTestFixtures;

	public function testDefaultRegistryResolvesRelationKinds(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);

		$loaders = HandlerRegistry::defaults();
		$post = $registry->getCollection('post');
		$user = $registry->getCollection('user');

		$this->assertSame(
			HasManyHandler::class,
			$loaders->resolve($user, 'posts', $user->relations->get('posts'))
		);
		$this->assertSame(
			ManyToManyHandler::class,
			$loaders->resolve($post, 'tags', $post->relations->get('tags'))
		);
	}

	public function testDuplicateRelationRegistrationFailsUnlessReplaced(): void
	{
		$loaders = HandlerRegistry::defaults();
		$loaders->relation('post', 'tags', ManyToManyHandler::class);

		$this->expectException(\LogicException::class);
		$loaders->relation('post', 'tags', ManyToManyHandler::class);
	}

	public function testRelationRegistrationCanBeReplacedExplicitly(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$post = $registry->getCollection('post');

		$loaders = HandlerRegistry::defaults();
		$loaders->relation('post', 'tags', HasManyHandler::class);
		$loaders->replaceRelation('post', 'tags', ManyToManyHandler::class);

		$this->assertSame(
			ManyToManyHandler::class,
			$loaders->resolve($post, 'tags', $post->relations->get('tags'))
		);
	}

	public function testDirectusParserKeepsInternalPrimaryKeysOutOfResponseFields(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);

		$query = (new DirectusQueryParser())->parse(
			$registry->getCollection('user'),
			['fields' => 'name,posts.tags.name']
		);

		$rootFields = array_values(array_filter($query->selection->nodes, fn ($node) => $node instanceof FieldSelection));
		$this->assertSame(['id', 'name'], array_map(fn (FieldSelection $node) => $node->field->field, $rootFields));
		$this->assertTrue($rootFields[0]->internal);

		$posts = $this->relation($query->selection->nodes, 'posts');
		$postFields = array_values(array_filter($posts->query->selection->nodes, fn ($node) => $node instanceof FieldSelection));
		$this->assertSame(['id'], array_map(fn (FieldSelection $node) => $node->field->field, $postFields));
		$this->assertTrue($postFields[0]->internal);

		$tags = $this->relation($posts->query->selection->nodes, 'tags');
		$tagFields = array_values(array_filter($tags->query->selection->nodes, fn ($node) => $node instanceof FieldSelection));
		$this->assertSame(['id', 'name'], array_map(fn (FieldSelection $node) => $node->field->field, $tagFields));
		$this->assertTrue($tagFields[0]->internal);
	}

	public function testAliasRegistryCreatesReadableUniqueAliases(): void
	{
		$aliases = new AliasRegistry();

		$this->assertSame('__on_tags_parent_key', $aliases->alias('__on_tags_parent_key'));
		$this->assertSame('__on_tags_parent_key_1', $aliases->alias('__on_tags_parent_key'));
		$this->assertSame('tags_parent_key', $aliases->alias('tags.parent-key'));
	}

	private function relation(array $nodes, string $name): RelationSelection
	{
		foreach ($nodes as $node) {
			if ($node instanceof RelationSelection && $node->responseName === $name) {
				return $node;
			}
		}

		$this->fail("Relation {$name} was not selected.");
	}
}
