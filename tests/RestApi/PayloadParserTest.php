<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\Data\Definition\Registry;
use ON\RestApi\Payload\Action\BasicRelationAction;
use ON\RestApi\Payload\Action\ConnectAction;
use ON\RestApi\Payload\Action\CreateAction;
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

		$spec = $parser->parse($post, [
			'title' => 'Hello',
			'tags' => [
				'create' => [['name' => 'New Tag']],
				'connect' => [1],
			],
		]);

		$this->assertSame('post', $spec->root->collection);
		$this->assertSame(['title' => 'Hello'], $spec->root->fields);
		$this->assertCount(1, $spec->root->relations);

		$tags = $spec->root->relations[0];
		$this->assertSame('tags', $tags->relationName);
		$this->assertCount(2, $tags->actions);
		$this->assertInstanceOf(CreateAction::class, $tags->actions[0]);
		$this->assertTrue($tags->actions[0]->explicitOperation);
		$this->assertInstanceOf(ConnectAction::class, $tags->actions[1]);
	}

	public function testParsesBasicRelationAsWrapper(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$post = $registry->getCollection('post');
		$parser = new DirectusPayloadParser();

		$spec = $parser->parse($post, [
			'title' => 'Hello',
			'tags' => [
				['name' => 'Inline'],
				2,
			],
		]);

		$tags = $spec->root->relations[0];
		$this->assertCount(1, $tags->actions);
		$this->assertInstanceOf(BasicRelationAction::class, $tags->actions[0]);
		$this->assertSame([
			['name' => 'Inline'],
			2,
		], $tags->actions[0]->items);
	}
}
