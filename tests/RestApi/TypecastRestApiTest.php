<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Registry;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\CycleSqliteTestDatabase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

#[RequiresPhpExtension('pdo_sqlite')]
final class TypecastRestApiTest extends TestCase
{
	use RestApiTestFixtures;

	public function testGetReturnsDatetimeAsPhpObject(): void
	{
		$registry = new Registry();
		$this->createPostCollection($registry);
		$db = $this->createTestDatabase();
		$service = $this->createRestApiService($registry, $this->createResolver($registry, $db));

		$item = $service->get('post', '1');

		$this->assertInstanceOf(\DateTimeImmutable::class, $item['created_at']);
		$this->assertSame('2025-01-10 10:00:00', $item['created_at']->format('Y-m-d H:i:s'));
	}

	public function testGetCanReturnRawStorageValues(): void
	{
		$registry = new Registry();
		$this->createPostCollection($registry);
		$db = $this->createTestDatabase();
		$service = $this->createRestApiService($registry, $this->createResolver($registry, $db));

		$item = $service->get('post', '1', null, ['raw' => true]);

		$this->assertSame('2025-01-10 10:00:00', $item['created_at']);
	}

	public function testSerializeReturnsIso8601ForDatetimeFields(): void
	{
		$registry = new Registry();
		$this->createPostCollection($registry);
		$db = $this->createTestDatabase();
		$service = $this->createRestApiService($registry, $this->createResolver($registry, $db));

		$item = $service->get('post', '1', null, ['serialize' => true]);

		$this->assertSame(
			(new \DateTimeImmutable('2025-01-10 10:00:00'))->format(\DateTimeInterface::ATOM),
			$item['created_at']
		);
	}

	public function testSerializeMethodReturnsWireFormatArray(): void
	{
		$registry = new Registry();
		$this->createPostCollection($registry);
		$db = $this->createTestDatabase();
		$service = $this->createRestApiService($registry, $this->createResolver($registry, $db));

		$item = $service->get('post', '1');
		$wire = $service->serialize($registry->getCollection('post'), $item);

		$this->assertIsArray($wire);
		$this->assertSame(
			(new \DateTimeImmutable('2025-01-10 10:00:00'))->format(\DateTimeInterface::ATOM),
			$wire['created_at']
		);
	}

	public function testCreatePersistsStorageFormatFromPhpDatetime(): void
	{
		$registry = new Registry();
		$this->createPostCollection($registry);
		$db = new CycleSqliteTestDatabase([
			'post' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'user_id' => 'INTEGER NOT NULL',
					'title' => 'TEXT',
					'content' => 'TEXT',
					'status' => 'TEXT',
					'created_at' => 'TEXT',
				],
				'rows' => [],
			],
		]);
		$resolver = $this->createResolver($registry, $db);
		$service = $this->createRestApiService($registry, $resolver);

		$created = $service->create('post', [
			'user_id' => 1,
			'title' => 'Typed post',
			'created_at' => new \DateTimeImmutable('2024-06-01T15:00:00+00:00'),
		], ['dispatchEvents' => false]);

		$this->assertInstanceOf(\DateTimeImmutable::class, $created['created_at']);
		$this->assertSame('2024-06-01 15:00:00', $created['created_at']->format('Y-m-d H:i:s'));

		$rows = $db->database()->getDriver()->query('SELECT created_at FROM post')->fetchAll();
		$this->assertSame('2024-06-01 15:00:00', $rows[0]['created_at']);
	}

	public function testUnserializeConvertsApiDatetimeToPhpObject(): void
	{
		$registry = new Registry();
		$this->createPostCollection($registry);
		$db = $this->createTestDatabase();
		$service = $this->createRestApiService($registry, $this->createResolver($registry, $db));

		$row = $service->unserialize($registry->getCollection('post'), [
			'created_at' => '2024-06-01T15:00:00+00:00',
		]);

		$this->assertInstanceOf(\DateTimeImmutable::class, $row['created_at']);
	}
}
