<?php

declare(strict_types=1);

namespace Tests\ON\DB;

use ArrayObject;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\ORM\Heap\Node;
use FilesystemIterator;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Stratigility\MiddlewarePipeInterface;
use ON\Application;
use ON\Config\ConfigExtension;
use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;
use ON\DataIntegration\DataExtension;
use ON\DB\ClosableDatabaseInterface;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseConfig;
use ON\DB\DatabaseExtension;
use ON\DB\DatabaseInterface;
use ON\DB\DatabaseManager;
use ON\DB\DatabasePool;
use ON\DB\DatabasePoolInterface;
use ON\DB\PdoDatabase;
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
		$lastOutsideDb = null;
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
			&$lastOutsideDb,
		): ResponseInterface {
			$db = $manager->getDatabase();
			$this->assertSame($db, $manager->getDatabase('cycle'));

			switch ($request->getUri()->getPath()) {
				case '/outside':
					$this->assertNotSame($startupDb, $db);
					$this->assertNotSame($lastOutsideDb, $db);
					$lastOutsideDb = $db;

					return new TextResponse('outside');

				case '/forwarded':
					$this->assertSame($firstDb, $db);
					$this->assertSame($firstPdo, $manager->getDatabaseConnection('pdo'));

					return new TextResponse('forwarded');

				case '/failure':
					$failedDb = $db;
					$failedEntity = $this->attachEntity($db);
					$failedDb->getConnection()->driver('sqlite')->connect();
					$this->assertNotSame($firstDb->getResource(), $db->getResource());
					$this->assertNotSame($firstDb->getEntityManager(), $db->getEntityManager());
					$this->assertNotSame($firstDb->getConnection(), $db->getConnection());
					$failedPdo = $manager->getDatabaseConnection('pdo');
					$this->assertFalse($failedPdo->inTransaction());
					$failedPdo->beginTransaction();

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
					$this->assertFalse($failedDb->getConnection()->driver('sqlite')->isConnected());
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
		$contextDb = $manager->getDatabase();
		$contextEntity = $this->attachEntity($contextDb);
		$otherManager = $container->make(DatabaseManager::class);
		$this->assertNotSame($contextDb, $otherManager->getDatabase());
		$this->assertSame('first', (string) $app->handle(new ServerRequest([], [], '/first', 'GET'))->getBody());
		$this->assertNotSame($contextDb, $firstDb);
		$this->assertFalse($firstDb->getResource()->getHeap()->has($firstEntity));
		$this->assertNotSame($pdo, $firstPdo);
		$this->assertSame('next', (string) $app->handle(new ServerRequest([], [], '/next', 'GET'))->getBody());
		$this->assertSame($contextDb, $manager->getDatabase());
		$this->assertTrue($contextDb->getResource()->getHeap()->has($contextEntity));
		CoroutineStub::finish();
		$this->assertFalse($contextDb->getResource()->getHeap()->has($contextEntity));
		$this->assertNotSame($contextDb, $manager->getDatabase());
		CoroutineStub::finish();

		CoroutineStub::$cid = 2;
		$handler = $this->createStub(RequestHandlerInterface::class);
		$this->assertSame('next', (string) $app->process(new ServerRequest([], [], '/next', 'GET'), $handler)->getBody());

		CoroutineStub::$cid = -1;
		$this->assertSame('outside', (string) $app->handle(new ServerRequest([], [], '/outside', 'GET'))->getBody());
		$this->assertSame('outside', (string) $app->handle(new ServerRequest([], [], '/outside', 'GET'))->getBody());
		$this->assertSame($startupDb, $manager->getDatabase());
		$this->assertTrue($startupDb->getResource()->getHeap()->has($startupEntity));
		$this->assertSame($pdo, $manager->getDatabaseResource('pdo'));
	}

	public function testDatabasePoolSupportsAnotherRuntime(): void
	{
		$app = $this->bootWorker(TestRuntimePool::class);
		$container = $app->ext('container')->getContainer();
		$pool = $container->get(TestRuntimePool::class);
		$manager = $container->get(DatabaseManager::class);

		$pool->id = 'first';
		$firstDb = $manager->getDatabase();
		$entity = $this->attachEntity($firstDb);
		$this->assertSame($firstDb, $manager->getDatabase());

		$pool->id = 'second';
		$secondDb = $manager->getDatabase();
		$this->assertNotSame($firstDb, $secondDb);
		$secondEntity = $this->attachEntity($secondDb);
		$pool->finish();
		$this->assertFalse($secondDb->getResource()->getHeap()->has($secondEntity));
		$this->assertTrue($firstDb->getResource()->getHeap()->has($entity));

		$this->assertNotSame($secondDb, $manager->getDatabase());
		$pool->finish();
		$pool->id = 'first';
		$app->pipe('/', function () use ($manager, $firstDb): ResponseInterface {
			$this->assertNotSame($firstDb, $manager->getDatabase());

			return new TextResponse('scoped');
		}, 1500);
		$this->assertSame('scoped', (string) $app->handle(new ServerRequest([], [], '/', 'GET'))->getBody());
		$this->assertSame($firstDb, $manager->getDatabase());
		$pool->finish();
		$this->assertFalse($firstDb->getResource()->getHeap()->has($entity));
	}

	public function testClosesCustomClosablePdoAndPreservesNonClosableDatabaseImplementers(): void
	{
		$app = $this->bootWorker(DatabasePool::class);
		$container = $app->ext('container')->getContainer();
		$config = $container->get(DatabaseConfig::class);
		$config->addDatabase(name: 'closable', class: TestClosableDatabase::class);
		$config->addDatabase(name: 'legacy', class: TestLegacyDatabase::class);
		$manager = $container->get(DatabaseManager::class);

		$firstPdoDb = $firstPdo = $firstClosable = $firstLegacy = null;
		$secondPdoDb = $secondClosable = $secondLegacy = null;

		$app->pipe('/', function (ServerRequestInterface $request) use (
			$manager,
			&$firstPdoDb,
			&$firstPdo,
			&$firstClosable,
			&$firstLegacy,
			&$secondPdoDb,
			&$secondClosable,
			&$secondLegacy,
		): ResponseInterface {
			if ($request->getUri()->getPath() === '/fail') {
				$firstPdoDb = $manager->getDatabase('pdo');
				$firstPdo = $firstPdoDb->getConnection();
				$firstPdo->beginTransaction();
				$firstClosable = $manager->getDatabase('closable');
				$firstLegacy = $manager->getDatabase('legacy');

				throw new RuntimeException('boom');
			}

			$secondPdoDb = $manager->getDatabase('pdo');
			$secondClosable = $manager->getDatabase('closable');
			$secondLegacy = $manager->getDatabase('legacy');

			return new TextResponse('ok');
		}, 1500);

		try {
			$app->handle(new ServerRequest([], [], '/fail', 'GET'));
			$this->fail('Expected /fail to throw.');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertInstanceOf(PdoDatabase::class, $firstPdoDb);
		$this->assertNull($firstPdoDb->getConnection());
		$this->assertNull($firstPdoDb->getResource());
		$this->assertTrue($firstPdo->inTransaction());
		$firstPdo->rollBack();
		$this->assertInstanceOf(TestClosableDatabase::class, $firstClosable);
		$this->assertSame(1, $firstClosable->closeCount);
		$this->assertInstanceOf(TestLegacyDatabase::class, $firstLegacy);

		$this->assertSame('ok', (string) $app->handle(new ServerRequest([], [], '/ok', 'GET'))->getBody());
		$this->assertNotSame($firstPdoDb, $secondPdoDb);
		$this->assertNotSame($firstClosable, $secondClosable);
		$this->assertNotSame($firstLegacy, $secondLegacy);
		$this->assertSame(1, $secondClosable->closeCount);
	}

	public function testScopeClosesAllDatabasesEvenWhenOneCloseThrows(): void
	{
		$app = $this->bootWorker(DatabasePool::class);
		$container = $app->ext('container')->getContainer();
		$config = $container->get(DatabaseConfig::class);
		$config->addDatabase(name: 'broken', class: TestFailingCloseDatabase::class);
		$config->addDatabase(name: 'healthy', class: TestClosableDatabase::class);
		$manager = $container->get(DatabaseManager::class);

		$healthy = null;
		$app->pipe('/', function () use ($manager, &$healthy): ResponseInterface {
			$manager->getDatabase('broken');
			$healthy = $manager->getDatabase('healthy');

			return new TextResponse('ok');
		}, 1500);

		try {
			$app->handle(new ServerRequest([], [], '/', 'GET'));
			$this->fail('Expected failing close to surface.');
		} catch (RuntimeException $e) {
			$this->assertSame('close failed', $e->getMessage());
		}

		$this->assertInstanceOf(TestClosableDatabase::class, $healthy);
		$this->assertSame(1, $healthy->closeCount);
	}

	public function testClosingPoolStillReleasesExecutionDatabasesWhenRequestCleanupThrows(): void
	{
		$pool = new DatabasePool();
		$owner = new stdClass();
		$executionDatabase = new TestClosableDatabase('execution');
		$pool->set($owner, 'execution', $executionDatabase);
		$pool->openRequest();
		$pool->set($owner, 'request', new TestFailingCloseDatabase('request'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('close failed');

		try {
			$pool->close();
		} finally {
			$this->assertSame(1, $executionDatabase->closeCount);
			$this->assertNull($pool->get($owner, 'execution'));
		}
	}

	private function bootWorker(?string $poolClass = null): Application
	{
		if ($poolClass === null) {
			if (class_exists(Coroutine::class)) {
				$this->markTestSkipped('This test simulates coroutine switches without the Swoole extension.');
			}
			class_alias(CoroutineStub::class, Coroutine::class);
		}

		$this->previousCwd = (string) getcwd();
		$this->projectDir = sys_get_temp_dir() . '/overnight-worker-db-' . bin2hex(random_bytes(8));
		mkdir($this->projectDir . '/config', recursive: true);
		file_put_contents($this->projectDir . '/config/pipeline.php', '<?php return static function (): void {};');
		if ($poolClass !== null) {
			$config = [
				ContainerConfig::class => [
					'definitions' => ['aliases' => [DatabasePoolInterface::class => $poolClass]],
				],
			];
			file_put_contents($this->projectDir . '/config/container.all.php', '<?php return ' . var_export($config, true) . ';');
		}

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

	/** @var array<int, ArrayObject> */
	private static array $contexts = [];

	/** @var array<int, list<callable>> */
	private static array $callbacks = [];

	public static function getCid(): int
	{
		return self::$cid;
	}

	public static function getContext(): ArrayObject
	{
		return self::$contexts[self::$cid] ??= new ArrayObject();
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
		unset(self::$contexts[self::$cid]);
	}
}

final class TestRuntimePool implements DatabasePoolInterface
{
	public ?string $id = null;

	/** @var array<string, DatabasePool> */
	private array $pools = [];

	private ?DatabasePool $fallback = null;

	public function get(object $owner, string $name): ?DatabaseInterface
	{
		return $this->databasePool()->get($owner, $name);
	}

	public function set(object $owner, string $name, DatabaseInterface $database): void
	{
		$this->databasePool()->set($owner, $name, $database);
	}

	public function openRequest(): bool
	{
		return $this->executionPool()?->openRequest() ?? false;
	}

	public function closeRequest(): void
	{
		$this->executionPool()?->closeRequest();
	}

	private function executionPool(): ?DatabasePool
	{
		if ($this->id === null) {
			return null;
		}

		return $this->pools[$this->id] ??= new DatabasePool();
	}

	private function databasePool(): DatabasePool
	{
		return $this->executionPool() ?? ($this->fallback ??= new DatabasePool());
	}

	public function finish(): void
	{
		$pool = $this->pools[$this->id];
		unset($this->pools[$this->id]);
		$pool->close();
	}
}

class TestLegacyDatabase implements DatabaseInterface
{
	public function __construct(private string $name)
	{
	}

	public function getConnection(): mixed
	{
		return null;
	}

	public function getResource(): mixed
	{
		return null;
	}

	public function setName(string $name): void
	{
		$this->name = $name;
	}

	public function getName(): string
	{
		return $this->name;
	}
}

final class TestClosableDatabase extends TestLegacyDatabase implements ClosableDatabaseInterface
{
	public int $closeCount = 0;

	public function close(): void
	{
		$this->closeCount++;
	}
}

final class TestFailingCloseDatabase extends TestLegacyDatabase implements ClosableDatabaseInterface
{
	public function close(): void
	{
		throw new RuntimeException('close failed');
	}
}
