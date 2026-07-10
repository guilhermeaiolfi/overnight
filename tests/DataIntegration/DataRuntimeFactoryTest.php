<?php

declare(strict_types=1);

namespace Tests\ON\DataIntegration;

use Cycle\Database\Config\DatabaseConfig as CycleDatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager as CycleDatabaseManager;
use ON\Application;
use ON\Data\DataRuntime;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use ON\DataIntegration\Container\DataRuntimeFactory;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class DataRuntimeFactoryTest extends TestCase
{
	public function testFactoryBuildsExecutableRuntimeFromCycleDatabaseAndGateway(): void
	{
		$cycleManager = new CycleDatabaseManager(new CycleDatabaseConfig([
			'default' => 'default',
			'databases' => [
				'default' => ['connection' => 'sqlite'],
			],
			'connections' => [
				'sqlite' => new SQLiteDriverConfig(
					connection: new MemoryConnectionConfig()
				),
			],
		]));
		$database = $cycleManager->database('default');
		$database->execute('CREATE TABLE item (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
		$database->execute("INSERT INTO item (name) VALUES ('alpha'), ('beta')");

		$cycle = $this->createMock(CycleDatabase::class);
		$cycle->method('getConnection')->willReturn($cycleManager);

		$dbManager = $this->createMock(DatabaseManager::class);
		$dbManager->method('getDatabase')->with('cycle')->willReturn($cycle);

		$app = $this->createMock(Application::class);
		$app->method('hasExtension')->with('data')->willReturn(false);

		$gateway = ConversionGateway::createDefault();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			static fn (string $id): bool => $id === DatabaseManager::class
		);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($app, $dbManager, $gateway): object {
				return match ($id) {
					Application::class => $app,
					DatabaseManager::class => $dbManager,
					ConversionGateway::class => $gateway,
					default => throw new \RuntimeException("Unexpected service {$id}"),
				};
			}
		);

		$runtime = (new DataRuntimeFactory())($container);
		$this->assertInstanceOf(DataRuntime::class, $runtime);

		$registry = new Registry();
		$registry->collection('item')
			->table('item')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->field('name', 'string')->end()
			->end();

		$query = $runtime->query($registry->getCollection('item'));
		$this->assertTrue($query->isExecutable());

		$rows = $query->select($query->id, $query->name)->orderBy($query->id->asc())->fetchAll();
		$this->assertSame([
			['id' => 1, 'name' => 'alpha'],
			['id' => 2, 'name' => 'beta'],
		], $rows);
	}
}
