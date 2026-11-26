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

	public function testRoundTripsBinaryScalarAndCompositeKeys(): void
	{
		$registry = new Registry();
		$registry->collection('document')
			->primaryKey('partition', 'identifier')
			->field('partition', 'int')->end()
			->field('identifier', 'string')->end()
			->end();

		$loader = new GenericDataLoader($registry);
		$identifier = hex2bin('fff8003afea012345678901234567890');
		$secondIdentifier = hex2bin('fff8003afea012345678901234567891');
		$fetchScalar = static function (array $keys) use ($identifier): array {
			self::assertSame([['id' => $identifier]], $keys);

			return ['first'];
		};
		$fetchComposite = static function (array $keys) use ($identifier, $secondIdentifier): array {
			self::assertSame([
				['identifier' => $identifier, 'partition' => '7'],
				['identifier' => $secondIdentifier, 'partition' => '7'],
			], $keys);

			return ['second', null];
		};

		$scalar = $loader->load('reference', $identifier, $fetchScalar);
		$composite = $loader->load('document', ['partition' => 7, 'identifier' => $identifier], $fetchComposite);
		$missingKey = ['identifier' => $secondIdentifier, 'partition' => 7];
		$missing = $loader->load('document', $missingKey, $fetchComposite);
		SyncPromiseQueue::run();

		$this->assertSame('first', $scalar->result);
		$this->assertSame('second', $composite->result);
		$this->assertNull($missing->result);
		$this->assertTrue($loader->has('document', $missingKey));
		$this->assertNull($loader->load('document', $missingKey, $fetchComposite));
		$this->assertSame('second', $loader->get('document', ['identifier' => $identifier, 'partition' => '7']));
	}
}
