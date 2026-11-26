<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager as CycleDatabaseManager;
use InvalidArgumentException;
use ON\Data\Definition\Registry;
use ON\DB\DatabaseManager;
use ON\RestApi\Addon\RevisionAddon;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Hook\RestHooks;
use ON\RestApi\RestApiConfig;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\CycleSqliteTestDatabase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;
use Throwable;

final class RevisionAddonTest extends TestCase
{
	use RestApiTestFixtures;

	public function testRecordsSuccessfulMutationsWithSafeSnapshotsAndGeneratedIds(): void
	{
		$cycle = new CycleDatabaseManager(new DatabaseConfig([
			'databases' => ['audit' => ['connection' => 'sqlite']],
			'connections' => ['sqlite' => new SQLiteDriverConfig(connection: new MemoryConnectionConfig())],
		]));
		$audit = $cycle->database('audit');
		$audit->execute('CREATE TABLE activity (collection TEXT, item_id TEXT, action TEXT, data TEXT, delta TEXT, created_at TEXT)');
		$manager = $this->createMock(DatabaseManager::class);
		$manager->expects($this->exactly(3))->method('getDatabaseConnection')->with('custom')->willReturn($cycle);

		$db = new CycleSqliteTestDatabase([
			'notes' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'title' => 'TEXT NOT NULL UNIQUE', 'internal' => 'TEXT'],
				'rows' => [],
			],
			'labels' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'title' => 'TEXT NOT NULL'],
				'rows' => [],
			],
		]);
		$registry = new Registry();
		$registry->collection('notes')
			->primaryKey('id')
			->field('id', 'primary')->autoIncrement(true)->end()
			->field('title', 'string')->end()
			->field('internal', 'string')->hidden(true)->end()
			->end();
		$registry->collection('labels')
			->primaryKey('id')
			->field('id', 'primary')->autoIncrement(true)->end()
			->field('title', 'string')->end()
			->end();

		$items = $this->createItems($registry, $db);
		$addon = new RevisionAddon($registry, $manager, $items, config: new RestApiConfig([
			'database' => 'custom',
			'cycleDatabase' => 'audit',
		]));
		$addon->register(['table' => 'activity', 'collections' => ['notes']]);

		$operations = $this->createDirectusOperations($registry, $items);
		RestHooks::for($registry->getCollection('notes'))->on('create.before', static function (ItemCreating $event): void {
			if ($event->getState()->getValue('title') === 'Canceled') {
				$event->preventDefault(['title' => 'Canceled']);
			}
		});

		$created = $operations->create('notes', ['title' => 'Draft', 'internal' => 'private']);
		$id = (string) $created['id'];
		$failure = null;

		try {
			$operations->create('notes', ['title' => 'Draft']);
		} catch (Throwable $error) {
			$failure = $error;
		}
		$this->assertNotNull($failure);
		$this->assertStringContainsString('UNIQUE', $failure->getMessage());
		$operations->update('notes', $id, ['title' => 'Published']);
		$operations->delete('notes', $id);
		$operations->create('notes', ['title' => 'Canceled']);
		$operations->create('labels', ['title' => 'Untracked']);

		$rows = $audit->query('SELECT collection, item_id, action, data, delta FROM activity ORDER BY rowid')->fetchAll();
		$this->assertSame(['create', 'update', 'delete'], array_column($rows, 'action'));
		$this->assertSame([$id, $id, $id], array_column($rows, 'item_id'));
		$this->assertSame(['notes', 'notes', 'notes'], array_column($rows, 'collection'));
		$this->assertNull($rows[0]['data']);
		$this->assertSame(['id' => (int) $id, 'title' => 'Draft'], json_decode($rows[0]['delta'], true));
		$this->assertSame(['id' => (int) $id, 'title' => 'Draft'], json_decode($rows[1]['data'], true));
		$this->assertSame(['title' => 'Published'], json_decode($rows[1]['delta'], true));
		$this->assertSame(['id' => (int) $id, 'title' => 'Published'], json_decode($rows[2]['data'], true));
		$this->assertNull($rows[2]['delta']);
	}

	public function testRejectsAnInvalidRevisionTableName(): void
	{
		$registry = new Registry();
		$addon = new RevisionAddon(
			$registry,
			$this->createMock(DatabaseManager::class),
			$this->createItems($registry, new CycleSqliteTestDatabase()),
		);

		$this->expectException(InvalidArgumentException::class);
		$addon->register(['table' => 'audit history']);
	}
}
