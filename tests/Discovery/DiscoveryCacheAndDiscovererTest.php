<?php

declare(strict_types=1);

namespace Tests\ON\Discovery;

use FilesystemIterator;
use InvalidArgumentException;
use ON\Application;
use ON\Config\AppConfig;
use ON\Config\ConfigExtension;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerExtension;
use ON\Discovery\AttributesDiscoverer;
use ON\Discovery\CacheAdapter\AggressiveCacheAdapter;
use ON\Discovery\CacheAdapter\NeverCacheAdapter;
use ON\Discovery\CacheAdapter\TimestampCacheAdapter;
use ON\Discovery\ClassFinder;
use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryExtension;
use ON\Discovery\DiscoveryItem;
use ON\Discovery\DiscoveryItems;
use ON\Discovery\DiscoveryLocation;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Router\Attribute\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stdClass;

final class DiscoveryCacheAndDiscovererTest extends TestCase
{
	private string $previousCwd;
	private string $projectDir;

	protected function setUp(): void
	{
		$this->previousCwd = (string) getcwd();
		$this->projectDir = sys_get_temp_dir() . '/overnight-discovery-test-' . bin2hex(random_bytes(8));
		mkdir($this->projectDir . '/config', 0755, true);
		mkdir($this->projectDir . '/var/cache', 0755, true);
		file_put_contents($this->projectDir . '/.env', "APP_DEBUG=true\nAPP_ENV=testing\n");
		chdir($this->projectDir);
		CountingDiscoverer::$applyCalls = 0;
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		CountingDiscoverer::$applyCalls = 0;
		$this->removeDirectory($this->projectDir);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalidLocationNamesProvider(): array
	{
		return [
			'forward slash' => ['foo/bar'],
			'backslash' => ['foo\\bar'],
			'dot-dot traversal' => ['../escape'],
			'nested dot-dot' => ['foo..bar'],
			'single dot' => ['.'],
			'empty' => [''],
			'whitespace only' => ['   '],
			'null byte' => ["valid\0name"],
		];
	}

	#[DataProvider('invalidLocationNamesProvider')]
	public function testCacheAdapterRejectsUnsafeLocationNames(string $invalidName): void
	{
		$app = $this->createMinimalApp();
		$adapter = new AggressiveCacheAdapter(new AppConfig(), $app);
		$discover = new InMemoryDiscoverer();
		$location = new DiscoveryLocation($invalidName, [$this->projectDir], AggressiveCacheAdapter::class);

		$this->expectException(InvalidArgumentException::class);
		$adapter->hasCache($discover, $location);
	}

	public function testAdaptersTreatUnconfiguredAttributeClassesAsCacheMisses(): void
	{
		$app = $this->createMinimalApp();
		$appCfg = new AppConfig();
		$location = new DiscoveryLocation('safe_loc', [$this->projectDir], AggressiveCacheAdapter::class);

		foreach ([
			new AggressiveCacheAdapter($appCfg, $app),
			new TimestampCacheAdapter($appCfg, $app),
		] as $adapter) {
			$discover = new InMemoryDiscoverer();
			$discover->getData()->add(new DiscoveryItem(new stdClass(), $location, dirty: true));
			$adapter->save($discover, $location);

			$fresh = new InMemoryDiscoverer();
			$adapter->recover($fresh, $location);

			$this->assertCount(0, $fresh->getData());
			$this->assertFalse($adapter->hasCache($fresh, $location));
			$adapter->clear($discover, $location);
		}
	}

	public function testAdaptersIgnoreCorruptNonDiscoveryItemsPayload(): void
	{
		$app = $this->createMinimalApp();
		$cacheDir = $this->projectDir . '/var/cache/discovery';
		$appCfg = new AppConfig(['discovery' => ['cache_path' => $cacheDir]]);
		$location = new DiscoveryLocation('corrupt_loc', [$this->projectDir], AggressiveCacheAdapter::class);
		$discover = new InMemoryDiscoverer();

		$adapter = new AggressiveCacheAdapter($appCfg, $app);
		$adapter->save($discover, $location);

		$files = glob($cacheDir . '/*.cache.php') ?: [];
		$this->assertCount(1, $files);

		// Overwrite with serialized non-DiscoveryItems array
		file_put_contents($files[0], serialize(['not' => 'DiscoveryItems']));

		$recovered = new InMemoryDiscoverer();
		$adapter->recover($recovered, $location);
		$this->assertCount(0, $recovered->getData());

		$tsAdapter = new TimestampCacheAdapter($appCfg, $app);
		$tsAdapter->recover($recovered, $location);
		$this->assertCount(0, $recovered->getData());
	}

	public function testAggressiveAndTimestampAdaptersRoundTripAllowedClassesAtomically(): void
	{
		$app = $this->createMinimalApp();
		$cacheDir = $this->projectDir . '/var/cache/discovery';
		$appCfg = new AppConfig(['discovery' => ['cache_path' => $cacheDir]]);
		$location = new DiscoveryLocation('routes', [$this->projectDir], TimestampCacheAdapter::class);

		$route = new Route('/hello', name: 'hello_route');
		$item = new DiscoveryItem([['method', 'index', Route::class, $route]], $location, dirty: true);
		$item->setFile(__FILE__);
		$item->setClassName('App\\HelloController');

		$discover = new InMemoryDiscoverer();
		$discover->getData()->add($item);

		$adapter = new TimestampCacheAdapter($appCfg, $app);
		$this->assertTrue($adapter->save($discover, $location));
		$this->assertFalse($item->isDirty());
		$this->assertSame([], glob($cacheDir . '/*.tmp') ?: []);

		$fresh = new InMemoryDiscoverer();
		$adapter->recover($fresh, $location);
		$items = iterator_to_array($fresh->getData());
		$this->assertCount(1, $items);
		$this->assertInstanceOf(Route::class, $items[0]->getValue()[0][3]);
		$this->assertSame('/hello', $items[0]->getValue()[0][3]->getPath());
	}

	public function testTimestampCacheAdapterOldestDoesNotOverwriteZeroWithNonZeroTimestamp(): void
	{
		$app = $this->createMinimalApp();
		$cacheDir = $this->projectDir . '/var/cache/discovery';
		$appCfg = new AppConfig(['discovery' => ['cache_path' => $cacheDir]]);
		$location = new DiscoveryLocation('mixed', [$this->projectDir], TimestampCacheAdapter::class);

		$uncachedDiscover = new UncachedDiscoverer();
		$cachedDiscover = new CachedDiscoverer();

		$adapter = new ExposedTimestampCacheAdapter($appCfg, $app);

		// Create a cache file ONLY for $cachedDiscover
		$item = new DiscoveryItem(['cached'], $location, dirty: true);
		$cachedDiscover->getData()->add($item);
		$this->assertTrue($adapter->save($cachedDiscover, $location));

		// Uncached first, cached second: oldest() MUST remain 0
		$this->assertSame(0.0, $adapter->exposeOldest([$uncachedDiscover, $cachedDiscover], $location));
		// Cached first, uncached second: oldest() MUST also be 0
		$this->assertSame(0.0, $adapter->exposeOldest([$cachedDiscover, $uncachedDiscover], $location));
	}

	public function testAttributesDiscovererMarksItemsDirtyAndPreservesMultiClassFiles(): void
	{
		$app = $this->createMinimalApp();
		$container = $app->ext('container')->getContainer();
		$cacheDir = $this->projectDir . '/var/cache/discovery';
		$app->ext('config')->get(AppConfig::class)->set('discovery.cache_path', $cacheDir);

		$suffix = 'Multi' . bin2hex(random_bytes(4));
		$namespace = "Tests\\ON\\Discovery\\Fixtures\\{$suffix}";
		$scanDir = $this->projectDir . '/pages';
		mkdir($scanDir, 0755, true);
		$fixtureFile = $scanDir . '/DualControllers.php';

		file_put_contents(
			$fixtureFile,
			<<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use ON\Router\Attribute\Route;

class FirstController
{
	#[Route('/first', name: 'first')]
	public function index(): void
	{
	}
}

class SecondController
{
	#[Route('/second', name: 'second')]
	public function show(): void
	{
	}
}
PHP
		);
		require_once $fixtureFile;

		$location = new DiscoveryLocation('pages', [$scanDir], TimestampCacheAdapter::class);
		$discoverer = new AttributesDiscoverer($app, $container, new ClassFinder());
		$discoverer->discover(new SplFileInfo($fixtureFile), $location);

		$items = iterator_to_array($discoverer->getData());
		$this->assertCount(2, $items, 'Both controllers in the same file must be preserved.');

		$classNames = array_map(static fn (DiscoveryItem $item): ?string => $item->getClassName(), $items);
		$this->assertSame(["{$namespace}\\FirstController", "{$namespace}\\SecondController"], $classNames);

		foreach ($items as $item) {
			$this->assertTrue($item->isDirty(), 'Newly discovered items must be marked dirty so TimestampCacheAdapter persists them.');
		}

		// Verify TimestampCacheAdapter persists the dirty items
		$adapter = new TimestampCacheAdapter($app->ext('config')->get(AppConfig::class), $app);
		$this->assertTrue($adapter->save($discoverer, $location));
		$this->assertTrue($adapter->hasCache($discoverer, $location));
	}

	public function testDiscoveryExtensionCallsApplyOnceAcrossMultipleLocations(): void
	{
		$loc1Dir = $this->projectDir . '/loc1';
		$loc2Dir = $this->projectDir . '/loc2';
		mkdir($loc1Dir, 0755, true);
		mkdir($loc2Dir, 0755, true);

		MultiLocationProbeExtension::$locations = [
			new DiscoveryLocation('loc1', [$loc1Dir], NeverCacheAdapter::class),
			new DiscoveryLocation('loc2', [$loc2Dir], NeverCacheAdapter::class),
		];

		new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [
				ConfigExtension::class => ['debug' => true],
				ContainerExtension::class => [],
				DiscoveryExtension::class => [],
				MultiLocationProbeExtension::class => [],
			],
			'debug' => true,
		]);

		$this->assertSame(1, CountingDiscoverer::$applyCalls);
	}

	private function createMinimalApp(): Application
	{
		return new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [
				ConfigExtension::class => ['debug' => true],
				ContainerExtension::class => [],
			],
			'debug' => true,
		]);
	}

	private function removeDirectory(string $directory): void
	{
		if (! is_dir($directory)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			if ($item->isDir() && ! $item->isLink()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}

		rmdir($directory);
	}
}


class InMemoryDiscoverer implements DiscoverInterface
{
	protected DiscoveryItems $items;

	public function __construct()
	{
		$this->items = new DiscoveryItems();
	}

	public function getData(): DiscoveryItems
	{
		return $this->items;
	}

	public function setData(DiscoveryItems $data): void
	{
		$this->items = $data;
	}

	public function addData(DiscoveryItems $data): void
	{
		foreach ($data as $item) {
			$this->items->add($item);
		}
	}

	public function apply(): bool
	{
		return true;
	}

	public function discover(SplFileInfo $file, DiscoveryLocation $location): void
	{
	}
}

final class UncachedDiscoverer extends InMemoryDiscoverer
{
}

final class CachedDiscoverer extends InMemoryDiscoverer
{
}

final class ExposedTimestampCacheAdapter extends TimestampCacheAdapter
{
	public function exposeOldest(array $discovers, DiscoveryLocation $location): float
	{
		return $this->oldest($discovers, $location);
	}
}

final class CountingDiscoverer extends InMemoryDiscoverer
{
	public static int $applyCalls = 0;

	public function apply(): bool
	{
		self::$applyCalls++;

		return true;
	}
}

final class MultiLocationProbeExtension extends AbstractExtension
{
	/** @var list<DiscoveryLocation> */
	public static array $locations = [];

	public function register(Init $init): void
	{
		$init->on(ConfigConfigureEvent::class, static function (ConfigConfigureEvent $event): void {
			$appCfg = $event->config->get(AppConfig::class);
			$appCfg->set('discovery.locations', self::$locations);
			$appCfg->set('discovery.discoverers', [
				CountingDiscoverer::class => [],
			]);
		});
	}
}
