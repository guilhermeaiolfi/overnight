<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Registry;
use ON\RestApi\Support\MutationInput;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class MutationInputTest extends TestCase
{
	use RestApiTestFixtures;

	public function testSplitNodeInputSeparatesScalarsAndRelations(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$collection = $registry->getCollection('post');

		[$scalar, $relations] = MutationInput::splitNodeInput($collection, [
			'title' => 'Hello',
			'comments' => [['body' => 'Nice']],
		]);

		$this->assertSame(['title' => 'Hello'], $scalar);
		$this->assertSame([['body' => 'Nice']], $relations['comments']);
	}

	public function testIsAssociativeArray(): void
	{
		$this->assertFalse(MutationInput::isAssociativeArray([]));
		$this->assertFalse(MutationInput::isAssociativeArray(['a', 'b']));
		$this->assertTrue(MutationInput::isAssociativeArray(['title' => 'Hello']));
		$this->assertTrue(MutationInput::isAssociativeArray(['create' => []]));
	}

	public function testNormalizeRelationItemsWrapsAssociativePayloadOnce(): void
	{
		$item = ['title' => 'Nested'];

		$this->assertSame([$item], MutationInput::normalizeRelationItems($item));
		$this->assertSame([['id' => 1], ['id' => 2]], MutationInput::normalizeRelationItems([['id' => 1], ['id' => 2]]));
		$this->assertSame([], MutationInput::normalizeRelationItems(null));
	}
}
