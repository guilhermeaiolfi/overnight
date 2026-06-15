<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Registry;
use ON\RestApi\Payload\Parser\DirectusPayloadParser;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class PayloadParserTest extends TestCase
{
	use RestApiTestFixtures;

	public function testParsesDetailedRelationOperations(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$post = $registry->getCollection('post');
		$parser = new DirectusPayloadParser();

		$node = $parser->parse($post, [
			'title' => 'Hello',
			'tags' => [
				'create' => [['name' => 'New Tag']],
				'delete' => [2],
			],
		]);

		$this->assertSame('post', $node->collection->getName());
		$this->assertSame(['title' => 'Hello'], $node->fields);
		$this->assertCount(1, $node->relations);

		$tags = $node->relations['tags'];
		$this->assertSame('tags', $tags->relationName);
		$this->assertCount(2, $tags->children);
		$this->assertSame('create', $tags->children[0]->plannedOperation);
		$this->assertSame(['name' => 'New Tag'], $tags->children[0]->relationData);
		$this->assertSame('delete', $tags->children[1]->plannedOperation);
		$this->assertSame(2, $tags->children[1]->relationData);
	}

	public function testParsesBasicRelationAsWrapper(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$post = $registry->getCollection('post');
		$parser = new DirectusPayloadParser();

		$node = $parser->parse($post, [
			'title' => 'Hello',
			'tags' => [
				['name' => 'Inline'],
				2,
			],
		]);

		$tags = $node->relations['tags'];
		$this->assertCount(2, $tags->children);
		$this->assertSame(['name' => 'Inline'], $tags->children[0]->relationData);
		$this->assertSame(2, $tags->children[1]->relationData);
		$this->assertNull($tags->children[0]->plannedOperation);
		$this->assertNull($tags->children[1]->plannedOperation);
	}
}
