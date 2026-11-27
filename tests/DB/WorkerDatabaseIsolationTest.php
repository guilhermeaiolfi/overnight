<?php

declare(strict_types=1);

namespace Tests\ON\DB;

use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\ORM\Heap\Node;
use FilesystemIterator;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Stratigility\MiddlewarePipeInterface;
use ON\Application;
use ON\Config\ConfigExtension;
use ON\Container\ContainerExtension;
use ON\DataIntegration\DataExtension;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseConfig;
use ON\DB\DatabaseExtension;
use ON\DB\DatabaseManager;
use ON\Event\EventsExtension;
use ON\Middleware\PipelineExtension;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use stdClass;
use Swoole\Coroutine;

final class WorkerDatabaseIsolationTest extends TestCase
{
	private string $projectDir;
	private string $previousCwd;

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testOverlappingRequestsIsolateConnectionsAndCleanOnlyTheirOwnHeaps(): void
	{
		$app = $this->bootWorker();
		$container = $app->ext('container')->getContainer();
		$manager = $container->get(DatabaseManager::class);
		$pipe = $container->get(MiddlewarePipeInterface::class);
		$startupDb = $manager->getDatabase();
		$startupEntity = $this->attachEntity($startupDb);
		$failure = new RuntimeException('request failed');
		$failedDb = $failedEntity = null;
		$firstDb = $firstEntity = $firstPdo = null;
		$pdo = $manager->getDatabaseConnection('pdo');

		$app->pipe('/', function (ServerRequestInterface $request) use (
			$app,
			$manager,
			$pipe,
			$startupDb,
			$failure,
			&$failedDb,
			&$failedEntity,
			&$firstDb,
			&$firstEntity,
			&$firstPdo,
		): ResponseInterface {
			$db = $manager->getDatabase();
			$this->assertSame($db, $manager->getDatabase('cycle'));

			switch ($request->getUri()->getPath()) {
				case '/outside':
					$this->assertSame($startupDb, $db);

					return new TextResponse('outside');

				case '/forwarded':
					$this->assertSame($firstDb, $db);
					$this->assertSame($firstPdo, $manager->getDatabaseConnection('pdo'));

					return new TextResponse('forwarded');

				case '/failure':
					$failedDb = $db;
					$failedEntity = $this->attachEntity($db);
					$this->assertNotSame($firstDb->getResource(), $db->getResource());
					$this->assertNotSame($firstDb->getEntityManager(), $db->getEntityManager());
					$this->assertNotSame($firstDb->getConnection(), $db->getConnection());
					$this->assertFalse($manager->getDatabaseConnection('pdo')->inTransaction());

					throw $failure;

				case '/first':
					$firstDb = $db;
					$this->assertNotSame($startupDb, $db);
					$firstEntity = $this->attachEntity($db);
					$firstPdo = $manager->getDatabaseConnection('pdo');
					$firstPdo->beginTransaction();

					try {
						CoroutineStub::$cid = 2;
						$pipe->handle(new ServerRequest([], [], '/failure', 'GET'));
						$this->fail('Expected the second request to throw.');
					} catch (RuntimeException $error) {
						$this->assertSame($failure, $error);
					} finally {
						CoroutineStub::$cid = 1;
						$this->assertTrue($firstPdo->inTransaction());
						$firstPdo->rollBack();
					}
					$this->assertFalse($failedDb->getResource()->getHeap()->has($failedEntity));
					$this->assertTrue($db->getResource()->getHeap()->has($firstEntity));
					$this->assertSame('forwarded', (string) $app->processForward('/forwarded', $request)->getBody());
					$this->assertTrue($db->getResource()->getHeap()->has($firstEntity));

					return new TextResponse('first');

				default:
					$this->assertNotSame($firstDb, $db);
					$this->assertNotSame($failedDb, $db);

					return new TextResponse('next');
			}
		}, 1500);

		CoroutineStub::$cid = 1;
		$this->assertSame('first', (string) $app->handle(new ServerRequest([], [], '/first', 'GET'))->getBody());
		$this->assertFalse($firstDb->getResource()->getHeap()->has($firstEntity));
		$this->assertNotSame($pdo, $firstPdo);
		$this->assertSame('next', (string) $app->handle(new ServerRequest([], [], '/next', 'GET'))->getBody());

		CoroutineStub::$cid = 2;
		$handler = $this->createStub(RequestHandlerInterface::class);
		$this->assertSame('next', (string) $app->process(new ServerRequest([], [], '/next', 'GET'), $handler)->getBody());

		CoroutineStub::$cid = -1;
		$this->assertSame('outside', (string) $app->handle(new ServerRequest([], [], '/outside', 'GET'))->getBody());
		$this->assertSame($startupDb, $manager->getDatabase());
		$this->assertTrue($startupDb->getResource()->getHeap()->has($startupEntity));
		$this->assertSame($pdo, $manager->getDatabaseResource('pdo'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testDatabaseLookupOutsideHttpIsScopedToTheCoroutine(): void
	{
		$app = $this->bootWorker();
		$manager = $app->ext('container')->getContainer()->get(DatabaseManager::class);

		CoroutineStub::$cid = 1;
		$firstDb = $manager->getDatabase();
		$entity = $this->attachEntity($firstDb);
		$this->assertSame($firstDb, $manager->getDatabase());

		CoroutineStub::$cid = 2;
		$secondDb = $manager->getDatabase();
		$this->assertNotSame($firstDb, $secondDb);
		$secondEntity = $this->attachEntity($secondDb);
		CoroutineStub::finish();
		$this->assertFalse($secondDb->getResource()->getHeap()->has($secondEntity));
		$this->assertTrue($firstDb->getResource()->getHeap()->has($entity));

		$this->assertNotSame($secondDb, $manager->getDatabase());
		CoroutineStub::finish();
		CoroutineStub::$cid = 1;
		$this->assertSame($firstDb, $manager->getDatabase());
		CoroutineStub::finish();
		$this->assertFalse($firstDb->getResource()->getHeap()->has($entity));
	}

	private function bootWorker(): Application
	{
		if (class_exists(Coroutine::class)) {
			$this->markTestSkipped('This test simulates coroutine switches without the Swoole extension.');
		}
		class_alias(CoroutineStub::class, Coroutine::class);

		$this->previousCwd = (string) getcwd();
		$this->projectDir = sys_get_temp_dir() . '/overnight-worker-db-' . bin2hex(random_bytes(8));
		mkdir($this->projectDir . '/config', recursive: true);
		file_put_contents($this->projectDir . '/config/pipeline.php', '<?php return static function (): void {};');

		$app = new Application([
			'paths' => ['project' => $this->projectDir],
			'disable_dotenv' => true,
			'debug' => false,
			'extensions' => [
				ConfigExtension::class => [],
				ContainerExtension::class => [],
				EventsExtension::class => [],
				DataExtension::class => [],
				PipelineExtension::class => [],
				DatabaseExtension::class => [],
			],
		]);

		$config = new DatabaseConfig();
		$config->addDatabase(name: 'cycle', class: CycleDatabase::class, options: [
			'default' => 'default',
			'databases' => ['default' => ['connection' => 'sqlite']],
			'connections' => [
				'sqlite' => new SQLiteDriverConfig(connection: new MemoryConnectionConfig()),
			],
		]);
		$config->addDatabase(name: 'pdo', dsn: 'sqlite::memory:');
		$app->ext('container')->getContainer()->set(DatabaseConfig::class, $config);

		return $app;
	}

	private function attachEntity(CycleDatabase $db): object
	{
		$entity = new stdClass();
		$db->getResource()->getHeap()->attach($entity, new Node(Node::MANAGED, ['id' => 1], 'user'));

		return $entity;
	}

	protected function tearDown(): void
	{
		if (! isset($this->projectDir)) {
			return;
		}

		chdir($this->previousCwd);
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->projectDir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($iterator as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($this->projectDir);
	}
}

final class CoroutineStub
{
	public static int $cid = -1;

	/** @var array<int, list<callable>> */
	private static array $callbacks = [];

	public static function getCid(): int
	{
		return self::$cid;
	}

	public static function defer(callable $callback): void
	{
		self::$callbacks[self::$cid][] = $callback;
	}

	public static function finish(): void
	{
		$callbacks = self::$callbacks[self::$cid] ?? [];
		unset(self::$callbacks[self::$cid]);
		foreach (array_reverse($callbacks) as $callback) {
			$callback();
		}
	}
}
