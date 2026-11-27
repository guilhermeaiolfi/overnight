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
use ON\DB\DatabaseStore;
use ON\DB\DatabaseStoreInterface;
use ON\DB\PdoDatabase;
use ON\Event\EventsExtension;
use ON\Middleware\PipelineExtension;
use ON\Swoole\SwooleDatabaseStore;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
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

		// Startup databases (e.g. GraphQLExtension resolvers) must survive HTTP dispatches intact.
		$startupCycleDb = $manager->getDatabase();
		$startupEntity = $this->attachEntity($startupCycleDb);
		$startupPdoDb = $manager->getDatabase('pdo');
		$startupPdo = $startupPdoDb->getConnection();

		$failure = new RuntimeException('request failed');
		$failedDb = $failedEntity = $failedPdo = null;
		$firstDb = $firstEntity = $firstPdo = null;
		$capturedRequest = null;
		$lastOutsideDb = null;

		$app->pipe('/', function (ServerRequestInterface $request) use (
			$app,
			$manager,
			$pipe,
			$startupCycleDb,
			$failure,
			&$failedDb,
			&$failedEntity,
			&$failedPdo,
			&$firstDb,
			&$firstEntity,
			&$firstPdo,
			&$capturedRequest,
			&$lastOutsideDb,
		): ResponseInterface {
			$db = $manager->getDatabase();
			$this->assertSame($db, $manager->getDatabase('cycle'));

			switch ($request->getUri()->getPath()) {
				case '/outside':
					$this->assertNotSame($startupCycleDb, $db);
					$this->assertNotSame($lastOutsideDb, $db);
					$lastOutsideDb = $db;
					$capturedRequest = $request;

					return new TextResponse('outside');

				case '/forwarded':
				case '/nested':
					$this->assertSame($firstDb, $db);
					$this->assertSame($firstPdo, $manager->getDatabaseConnection('pdo'));

					return new TextResponse(ltrim($request->getUri()->getPath(), '/'));

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
					$this->assertNotSame($startupCycleDb, $db);
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
					}

					$this->assertFalse($failedDb->getResource()->getHeap()->has($failedEntity));
					$this->assertFalse($failedDb->getConnection()->driver('sqlite')->isConnected());
					$this->assertFalse($failedPdo->inTransaction());
					$this->assertTrue($db->getResource()->getHeap()->has($firstEntity));

					// Forward AND nested fresh request in same coroutine share the parent scope without closing it.
					$this->assertSame('forwarded', (string) $app->processForward('/forwarded', $request)->getBody());
					$this->assertSame('nested', (string) $pipe->handle(new ServerRequest([], [], '/nested', 'GET'))->getBody());
					$this->assertTrue($firstPdo->inTransaction());
					$this->assertTrue($db->getResource()->getHeap()->has($firstEntity));
					$firstPdo->rollBack();

					return new TextResponse('first');

				default:
					$this->assertNotSame($firstDb, $db);
					$this->assertNotSame($failedDb, $db);

					return new TextResponse('next');
			}
		}, 1500);

		CoroutineStub::$cid = 1;
		$backgroundDb = $manager->getDatabase();
		$backgroundEntity = $this->attachEntity($backgroundDb);
		$this->assertSame('first', (string) $app->handle(new ServerRequest([], [], '/first', 'GET'))->getBody());
		$this->assertNotSame($backgroundDb, $firstDb);
		$this->assertFalse($firstDb->getResource()->getHeap()->has($firstEntity));
		$this->assertSame('next', (string) $app->handle(new ServerRequest([], [], '/next', 'GET'))->getBody());
		$this->assertSame($backgroundDb, $manager->getDatabase());
		$this->assertTrue($backgroundDb->getResource()->getHeap()->has($backgroundEntity));
		CoroutineStub::finish();
		$this->assertFalse($backgroundDb->getResource()->getHeap()->has($backgroundEntity));
		$this->assertNotSame($backgroundDb, $manager->getDatabase());
		CoroutineStub::finish();

		CoroutineStub::$cid = 2;
		$handler = $this->createStub(RequestHandlerInterface::class);
		$this->assertSame('next', (string) $app->process(new ServerRequest([], [], '/next', 'GET'), $handler)->getBody());

		// Sequential requests and redispatching a retained request outside a coroutine still isolate and clean up.
		CoroutineStub::$cid = -1;
		$this->assertSame('outside', (string) $app->handle(new ServerRequest([], [], '/outside', 'GET'))->getBody());
		$firstOutsideDb = $lastOutsideDb;
		$this->assertSame('outside', (string) $app->handle($capturedRequest)->getBody());
		$this->assertNotSame($firstOutsideDb, $lastOutsideDb);

		// Startup databases remain live and usable after HTTP dispatches.
		$this->assertSame($startupCycleDb, $manager->getDatabase());
		$this->assertTrue($startupCycleDb->getResource()->getHeap()->has($startupEntity));
		$this->assertSame($startupPdo, $startupPdoDb->getConnection());
		$this->assertSame('1', (string) $startupPdoDb->getConnection()->query('SELECT 1')->fetchColumn());
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testMultipleSwooleStoresDoNotCollideAndSkipDeferWhenUnused(): void
	{
		if (class_exists(Coroutine::class)) {
			$this->markTestSkipped('This test simulates coroutine switches without the Swoole extension.');
		}
		class_alias(CoroutineStub::class, Coroutine::class);

		$storeA = new SwooleDatabaseStore();
		$storeB = new SwooleDatabaseStore();

		CoroutineStub::$cid = 10;
		// Running a scope or closing without touching any DB does not register deferred cleanup.
		$this->assertSame('ok', $storeA->scoped(static fn (): string => 'ok'));
		$storeA->close();
		$this->assertSame(0, CoroutineStub::deferredCount(10));

		$dbA = new TestClosableDatabase('a');
		$dbB = new TestClosableDatabase('b');
		$storeA->set('default', $dbA);
		$storeB->set('default', $dbB);

		$this->assertSame($dbA, $storeA->get('default'));
		$this->assertSame($dbB, $storeB->get('default'));

		$storeA->close();
		$this->assertSame(1, $dbA->closeCount);
		$this->assertSame(0, $dbB->closeCount);
		$this->assertNull($storeA->get('default'));
		$this->assertSame($dbB, $storeB->get('default'));

		CoroutineStub::finish();
		$this->assertSame(1, $dbA->closeCount);
		$this->assertSame(1, $dbB->closeCount);
	}

	public function testDatabaseStoreSupportsFactoryBoundRuntime(): void
	{
		$app = $this->bootWorker(factoryClass: TestRuntimeStoreFactory::class);
		$container = $app->ext('container')->getContainer();
		$store = $container->get(DatabaseStoreInterface::class);
		$this->assertInstanceOf(TestRuntimeStore::class, $store);
		$manager = $container->get(DatabaseManager::class);

		$store->id = 'first';
		$firstDb = $manager->getDatabase();
		$entity = $this->attachEntity($firstDb);
		$this->assertSame($firstDb, $manager->getDatabase());

		$store->id = 'second';
		$secondDb = $manager->getDatabase();
		$this->assertNotSame($firstDb, $secondDb);
		$secondEntity = $this->attachEntity($secondDb);
		$store->finish();
		$this->assertFalse($secondDb->getResource()->getHeap()->has($secondEntity));
		$this->assertTrue($firstDb->getResource()->getHeap()->has($entity));

		$this->assertNotSame($secondDb, $manager->getDatabase());
		$store->finish();

		$store->id = 'first';
		$app->pipe('/', function () use ($manager, $firstDb): ResponseInterface {
			$this->assertNotSame($firstDb, $manager->getDatabase());

			return new TextResponse('scoped');
		}, 1500);
		$this->assertSame('scoped', (string) $app->handle(new ServerRequest([], [], '/', 'GET'))->getBody());
		$this->assertSame($firstDb, $manager->getDatabase());
		$this->assertTrue($firstDb->getResource()->getHeap()->has($entity));
		$store->finish();
		$this->assertFalse($firstDb->getResource()->getHeap()->has($entity));
	}

	public function testClosesCustomClosablePdoWrapperPersistentAndNonClosableDatabaseImplementers(): void
	{
		$app = $this->bootWorker(aliasClass: DatabaseStore::class);
		$container = $app->ext('container')->getContainer();
		$config = $container->get(DatabaseConfig::class);
		$persistentPath = $this->projectDir . '/persistent.sqlite';
		$config->addDatabase(name: 'closable', class: TestClosableDatabase::class);
		$config->addDatabase(name: 'legacy', class: TestLegacyDatabase::class);
		$config->addDatabase(
			name: 'wrapped_pdo',
			dsn: 'sqlite::memory:',
			wrapper_class: TestDelegatingPdoWrapper::class,
		);
		$config->addDatabase(
			name: 'persistent_pdo',
			dsn: 'sqlite:' . $persistentPath,
			persistent: true,
		);
		$manager = $container->get(DatabaseManager::class);

		// A separate persistent handle in the same process shares the underlying connection.
		$siblingPersistent = new PDO('sqlite:' . $persistentPath, null, null, [PDO::ATTR_PERSISTENT => true]);
		$siblingPersistent->beginTransaction();

		$firstPdoDb = $firstPdo = $firstWrappedDb = $firstWrappedPdo = $firstPersistentDb = $firstPersistentPdo = null;
		$firstClosable = $firstLegacy = null;
		$secondPdoDb = $secondClosable = $secondLegacy = null;

		$app->pipe('/', function (ServerRequestInterface $request) use (
			$manager,
			&$firstPdoDb,
			&$firstPdo,
			&$firstWrappedDb,
			&$firstWrappedPdo,
			&$firstPersistentDb,
			&$firstPersistentPdo,
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

				$firstWrappedDb = $manager->getDatabase('wrapped_pdo');
				$firstWrappedPdo = $firstWrappedDb->getConnection()->pdo;
				$firstWrappedPdo->beginTransaction();

				$firstPersistentDb = $manager->getDatabase('persistent_pdo');
				$firstPersistentPdo = $firstPersistentDb->getConnection();
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
		} finally {
			$persistentTxActive = $siblingPersistent->inTransaction();
			if ($persistentTxActive) {
				$siblingPersistent->rollBack();
			}
			$firstPersistentPdo = null;
			$siblingPersistent = null;
		}

		$this->assertInstanceOf(PdoDatabase::class, $firstPdoDb);
		$this->assertNull($firstPdoDb->getConnection());
		$this->assertNull($firstPdoDb->getResource());
		$this->assertFalse($firstPdo->inTransaction());

		// Delegating non-PDO wrapper still rolls back the underlying PDO transaction.
		$this->assertInstanceOf(PdoDatabase::class, $firstWrappedDb);
		$this->assertNull($firstWrappedDb->getConnection());
		$this->assertFalse($firstWrappedPdo->inTransaction());

		// Persistent PDO drops wrapper references without rolling back sibling handle's transaction.
		$this->assertInstanceOf(PdoDatabase::class, $firstPersistentDb);
		$this->assertNull($firstPersistentDb->getConnection());
		$this->assertTrue($persistentTxActive);

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
		$app = $this->bootWorker(aliasClass: DatabaseStore::class);
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
		$this->assertNotSame($healthy, $manager->getDatabase('healthy'));
	}

	public function testPdoPreservesNamedPersistentPoolsAndUpdatesPersistenceOnSetConnection(): void
	{
		$this->previousCwd = (string) getcwd();
		$this->projectDir = sys_get_temp_dir() . '/overnight-worker-db-' . bin2hex(random_bytes(8));
		mkdir($this->projectDir, recursive: true);
		$dsn = 'sqlite:' . $this->projectDir . '/named-pools.sqlite';

		$config = new DatabaseConfig();
		$config->addDatabase(name: 'pool_a', dsn: $dsn, options: [PDO::ATTR_PERSISTENT => 'pool_a']);
		$config->addDatabase(name: 'pool_b', dsn: $dsn, options: [PDO::ATTR_PERSISTENT => 'pool_b']);
		$config->addDatabase(name: 'dedicated', dsn: 'sqlite::memory:');

		$dbA = new PdoDatabase('pool_a', $config);
		$dbB = new PdoDatabase('pool_b', $config);
		$pdoA = $dbA->getConnection();
		$pdoB = $dbB->getConnection();

		try {
			$pdoA->beginTransaction();
			$this->assertTrue($pdoA->inTransaction());
			$this->assertFalse($pdoB->inTransaction());

			// Replacing a non-persistent wrapper's handle with a persistent handle avoids active rollback on close().
			$dedicated = new PdoDatabase('dedicated', $config);
			$siblingPoolA = new PDO($dsn, null, null, [PDO::ATTR_PERSISTENT => 'pool_a']);
			$dedicated->setConnection($siblingPoolA);
			$dedicated->close();

			$this->assertNull($dedicated->getConnection());
			$this->assertTrue($pdoA->inTransaction());
		} finally {
			if ($pdoA->inTransaction()) {
				$pdoA->rollBack();
			}
			$siblingPoolA = null;
			$pdoA = null;
			$pdoB = null;
			$dbA->close();
			$dbB->close();
		}
	}

	private function bootWorker(?string $aliasClass = null, ?string $factoryClass = null): Application
	{
		if ($aliasClass === null && $factoryClass === null) {
			if (class_exists(Coroutine::class)) {
				$this->markTestSkipped('This test simulates coroutine switches without the Swoole extension.');
			}
			class_alias(CoroutineStub::class, Coroutine::class);
		}

		$this->previousCwd = (string) getcwd();
		$this->projectDir = sys_get_temp_dir() . '/overnight-worker-db-' . bin2hex(random_bytes(8));
		mkdir($this->projectDir . '/config', recursive: true);
		file_put_contents($this->projectDir . '/config/pipeline.php', '<?php return static function (): void {};');
		if ($aliasClass !== null) {
			$config = [
				ContainerConfig::class => [
					'definitions' => ['aliases' => [DatabaseStoreInterface::class => $aliasClass]],
				],
			];
			file_put_contents($this->projectDir . '/config/container.all.php', '<?php return ' . var_export($config, true) . ';');
		} elseif ($factoryClass !== null) {
			$config = [
				ContainerConfig::class => [
					'definitions' => ['factories' => [DatabaseStoreInterface::class => $factoryClass]],
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
			$file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
		}
		@rmdir($this->projectDir);
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

	public static function deferredCount(int $cid): int
	{
		return count(self::$callbacks[$cid] ?? []);
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

final class TestRuntimeStoreFactory
{
	public function __invoke(ContainerInterface $container): TestRuntimeStore
	{
		return new TestRuntimeStore();
	}
}

final class TestRuntimeStore implements DatabaseStoreInterface
{
	public ?string $id = null;

	/** @var array<string, DatabaseStore> */
	private array $stores = [];

	private ?DatabaseStore $fallback = null;

	public function get(string $name): ?DatabaseInterface
	{
		return $this->currentStore(create: false)?->get($name);
	}

	public function set(string $name, DatabaseInterface $database): void
	{
		$this->currentStore(create: true)->set($name, $database);
	}

	public function scoped(callable $callback): mixed
	{
		return $this->currentStore(create: true)->scoped($callback);
	}

	public function close(): void
	{
		$this->currentStore(create: false)?->close();
	}

	public function finish(): void
	{
		$store = $this->stores[$this->id];
		unset($this->stores[$this->id]);
		$store->close();
	}

	private function currentStore(bool $create): ?DatabaseStore
	{
		if ($this->id !== null) {
			if (! isset($this->stores[$this->id])) {
				if (! $create) {
					return null;
				}
				$this->stores[$this->id] = new DatabaseStore();
			}

			return $this->stores[$this->id];
		}

		if (! $create) {
			return $this->fallback;
		}

		return $this->fallback ??= new DatabaseStore();
	}
}

final class TestDelegatingPdoWrapper
{
	public function __construct(public PDO $pdo)
	{
	}

	public function setAttribute(int $attribute, mixed $value): bool
	{
		return $this->pdo->setAttribute($attribute, $value);
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
