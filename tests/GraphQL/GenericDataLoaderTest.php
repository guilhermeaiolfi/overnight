<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL;

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromiseQueue;
use ON\Data\Definition\Registry;
use ON\GraphQL\DataLoader\GenericDataLoader;
use PHPUnit\Framework\TestCase;

final class GenericDataLoaderTest extends TestCase
{
	public function testBatchesStringKeysAndCachesMissingRows(): void
	{
		$registry = new Registry();
		$registry->collection('document')
			->primaryKey('identifier')
			->field('identifier', 'string')->column('document_id')->end()
			->end();

		$loader = new GenericDataLoader($registry);
		$batches = [];
		$fetch = static function (array $keys) use (&$batches): array {
			$batches[] = $keys;

			return ['document', null];
		};

		$present = $loader->load('document', 'urn:document:1', $fetch);
		$missing = $loader->load('document', 'urn:document:2', $fetch);
		$this->assertInstanceOf(Deferred::class, $present);
		$this->assertInstanceOf(Deferred::class, $missing);
		SyncPromiseQueue::run();

		$this->assertSame([[['document_id' => 'urn:document:1'], ['document_id' => 'urn:document:2']]], $batches);
		$this->assertSame('document', $present->result);
		$this->assertNull($missing->result);
		$this->assertNull($loader->load('document', 'urn:document:2', $fetch));

		$loader->clearEntity('document');
		$this->assertFalse($loader->has('document', 'urn:document:2'));
	}
}
