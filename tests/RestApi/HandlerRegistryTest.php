<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use LogicException;
use ON\Data\Definition\Registry;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Selection\SelectionTag;
use ON\RestApi\Handler\HandlerRegistry;
use ON\RestApi\Handler\HasManyHandler;
use ON\RestApi\Handler\ManyToManyHandler;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class HandlerRegistryTest extends TestCase
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

		$this->expectException(LogicException::class);
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

		$query = $this->createQueryParser()->parse(
			$registry->getCollection('user'),
			['fields' => 'name,posts.tags.name'],
			new \ON\RestApi\Query\QueryContext(),
		);

		$publicNames = [];
		foreach ($query->getSelections()->getByTag(SelectionTag::PUBLIC) as $selection) {
			$expr = $selection->getExpression();
			if ($expr instanceof FieldRef) {
				$publicNames[] = $expr->getName();
			}
		}
		$this->assertSame(['name'], $publicNames);

		$internalNames = [];
		foreach ($query->getSelections()->getByTag(SelectionTag::INTERNAL) as $selection) {
			$expr = $selection->getExpression();
			if ($expr instanceof FieldRef) {
				$internalNames[] = $expr->getName();
			}
		}
		$this->assertContains('id', $internalNames);

		$posts = $query->relation('posts');
		$this->assertTrue($posts->isSelected());
		$tags = $posts->relation('tags');
		$this->assertTrue($tags->isSelected());
		$this->assertSame(['name'], $tags->getFields());
	}
}
