<?php

declare(strict_types=1);

namespace Tests\ON\DB\Cycle;

use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class CycleDatabaseTest extends TestCase
{
	private function createDatabase(): CycleDatabase
	{
		$config = new DatabaseConfig([
			'databases' => [
				'default' => [
					'class' => CycleDatabase::class,
					'options' => [
						'default' => 'default',
						'databases' => [
							'default' => ['connection' => 'sqlite'],
						],
						'connections' => [
							'sqlite' => new SQLiteDriverConfig(
								connection: new MemoryConnectionConfig(),
							),
						],
					],
				],
			],
		]);

		return new CycleDatabase('default', $config);
	}

	public function testGetRegistryThrowsRuntimeExceptionBeforeInitialization(): void
	{
		$db = $this->createDatabase();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('CycleDatabase "default" registry has not been initialized');

		$db->getRegistry();
	}

	public function testGetEntityManagerThrowsRuntimeExceptionBeforeInitialization(): void
	{
		$db = $this->createDatabase();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('CycleDatabase "default" entity manager has not been initialized');

		$db->getEntityManager();
	}

	public function testCleanHeapClearsOrmIdentityMapAndIsSafeBeforeSchemaInit(): void
	{
		$db = $this->createDatabase();

		// Safe no-op before setSchema()
		$db->cleanHeap();

		$db->setSchema(new Schema([]));

		/** @var ORM $orm */
		$orm = $db->getResource();
		$entity = new stdClass();
		$node = new Node(Node::MANAGED, ['id' => 1], 'user');
		$orm->getHeap()->attach($entity, $node);

		$this->assertTrue($orm->getHeap()->has($entity));

		$db->cleanHeap();

		$this->assertFalse($orm->getHeap()->has($entity));
	}

	public function testCloseCleansHeapAndDisconnectsDrivers(): void
	{
		$db = $this->createDatabase();
		$db->setSchema(new Schema([]));

		/** @var ORM $orm */
		$orm = $db->getResource();
		$entity = new stdClass();
		$orm->getHeap()->attach($entity, new Node(Node::MANAGED, ['id' => 1], 'user'));

		$driver = $db->getConnection()->driver('sqlite');
		$driver->connect();
		$driver->beginTransaction();

		$this->assertTrue($driver->isConnected());
		$this->assertSame(1, $driver->getTransactionLevel());

		$db->close();

		$this->assertFalse($orm->getHeap()->has($entity));
		$this->assertFalse($driver->isConnected());
		$this->assertSame(0, $driver->getTransactionLevel());

		// Repeated close is safe and idempotent.
		$db->close();
		$this->assertFalse($driver->isConnected());
	}
}
