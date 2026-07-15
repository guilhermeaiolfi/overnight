<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\Data\Definition\Registry;
use ON\RestApi\Mutation\BoundItemState;
use ON\RestApi\Mutation\Payload\PayloadPath;
use PHPUnit\Framework\TestCase;
use stdClass;

final class BoundItemStateIsCreateTest extends TestCase
{
	public function testCreateStateReportsIsCreateTrueAndKeepsPendingOverlay(): void
	{
		$collection = (new Registry())->collection('article')
			->table('articles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->end()
			->getCollection('article');

		$state = new BoundItemState(
			$collection,
			new stdClass(),
			['title' => 'Hello'],
			null,
			false,
			PayloadPath::root(),
			identityMutable: true,
			creating: true,
		);

		$this->assertTrue($state->isCreate());
		$this->assertSame(['title' => 'Hello'], $state->getData());
	}

	public function testUpdateStateReportsIsCreateFalseEvenWhenOverlayOmitsPrimaryKey(): void
	{
		$collection = (new Registry())->collection('news_article_file')
			->table('news_files')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('sequence', 'int')->end()
			->field('file_id', 'int')->end()
			->end()
			->getCollection('news_article_file');

		// Mirrors binder behavior for existing related images: PK stripped from overlay.
		$state = new BoundItemState(
			$collection,
			new stdClass(),
			['sequence' => 0, 'alt_text' => 'Capa'],
			['id' => 42, 'sequence' => 0, 'file_id' => 7],
			true,
			new PayloadPath(['images', 0]),
			identityMutable: false,
			creating: false,
		);

		$this->assertFalse($state->isCreate());
		$this->assertArrayNotHasKey('id', $state->getData());
		$this->assertSame(42, $state->getValue('id'));
	}
}
