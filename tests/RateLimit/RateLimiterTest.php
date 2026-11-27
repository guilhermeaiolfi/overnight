<?php

declare(strict_types=1);

namespace Tests\ON\RateLimit;

use DI\Container;
use InvalidArgumentException;
use ON\Cache\Cache;
use ON\Cache\CacheConfig;
use ON\Cache\CacheInterface;
use ON\Cache\Container\CacheFactory;
use ON\RateLimit\CacheRateLimiter;
use ON\RateLimit\Container\RateLimiterFactory;
use ON\RateLimit\InMemoryRateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class RateLimiterTest extends TestCase
{
	public function testCachesAnIpv6ClientSeparatelyForEachRateLimitPolicy(): void
	{
		$cache = new Cache(new ArrayAdapter(), true);
		$api = new CacheRateLimiter($cache, 'api:v1');
		$uploads = new CacheRateLimiter($cache, 'api_v1');
		$client = '2001:db8::12';

		$this->assertTrue($api->attempt($client, 1)->isAllowed());
		$this->assertFalse($api->peek($client, 1)->isAllowed());
		$this->assertTrue($uploads->attempt($client, 1)->isAllowed());
		$api->reset($client);
		$this->assertTrue($api->peek($client, 1)->isAllowed());
		$this->assertFalse($uploads->peek($client, 1)->isAllowed());
	}

	public function testCacheKeysFitThePsr6GuaranteedLength(): void
	{
		$adapter = new class () extends ArrayAdapter {
			public function getItem(mixed $key): CacheItem
			{
				if (strlen($key) > 64) {
					throw new InvalidArgumentException('The cache key exceeds 64 characters.');
				}

				return parent::getItem($key);
			}
		};
		$limiter = new CacheRateLimiter(new Cache($adapter, true));

		$this->assertTrue($limiter->attempt('api-user', 10)->isAllowed());
	}

	public function testIncrementKeepsStorageExpirationTiedToTheOriginalWindow(): void
	{
		$adapter = new ArrayAdapter();
		$cache = new Cache($adapter, true);
		$start = time() - 45;
		$key = hash('sha256', '10:ratelimit_client');
		$cache->put($key, ['count' => 1, 'start' => $start]);

		$result = (new CacheRateLimiter($cache))->attempt('client', 3, 60);

		$this->assertTrue($result->isAllowed());
		$this->assertSame(1, $result->getRemaining());
		$this->assertSame($start + 61, (int) $adapter->getItem($key)->getMetadata()[CacheItem::METADATA_EXPIRY]);
	}

	public function testFactoryRejectsAnEnabledUnsupportedCache(): void
	{
		$container = $this->createStub(ContainerInterface::class);
		$container->method('has')->with(CacheInterface::class)->willReturn(true);
		$container->method('get')->with(CacheInterface::class)->willReturn(new Cache(new NullAdapter(), true));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('RateLimiterInterface');

		(new RateLimiterFactory())($container);
	}

	public function testFailedSaveDoesNotAdmitAnAttempt(): void
	{
		$adapter = $this->createMock(ArrayAdapter::class);
		$adapter->method('getItem')->willReturn((new ArrayAdapter())->getItem('client'));
		$adapter->method('save')->willReturn(false);
		$limiter = new CacheRateLimiter(new Cache($adapter, true));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unable to save the rate limit counter');

		$limiter->attempt('client');
	}

	public function testUnusableConfiguredLockDirectoryRejectsAnAttempt(): void
	{
		$directory = tempnam(sys_get_temp_dir(), 'overnight-ratelimit-');
		$files = new Filesystem();

		try {
			$container = new Container();
			$container->set(CacheConfig::class, new CacheConfig([
				'enable' => true,
				'lockDirectory' => $directory,
			]));
			$container->set(FilesystemAdapter::class, new FilesystemAdapter('rate-limit', 0, $directory . '-cache'));
			$container->set(CacheInterface::class, (new CacheFactory())($container));
			$limiter = (new RateLimiterFactory())($container);

			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage('Unable to create cache lock directory');
			$limiter->attempt('client');
		} finally {
			$files->remove([$directory, $directory . '-cache']);
		}
	}

	public function testFilesystemAttemptsAreSerializedAcrossProcesses(): void
	{
		if (! function_exists('proc_open')) {
			$this->markTestSkipped('This test requires proc_open.');
		}

		$directory = sys_get_temp_dir() . '/overnight-ratelimit-test-' . bin2hex(random_bytes(8));
		$files = new Filesystem();
		$files->mkdir($directory);
		$workers = [];

		try {
			$cache = new Cache(new FilesystemAdapter('rate-limit', 0, $directory), true);
			$limiter = new CacheRateLimiter($cache->withNamespace('api'));
			$this->assertTrue($limiter->attempt('client', 2)->isAllowed());

			for ($index = 0; $index < 4; $index++) {
				$worker = new Process([
					PHP_BINARY,
					__DIR__ . '/fixtures/contended-attempt.php',
					dirname(__DIR__, 2) . '/vendor/autoload.php',
					$directory,
					(string) $index,
				], timeout: 10);
				$workers[] = $worker;
				$worker->start();
			}
			$deadline = microtime(true) + 5;
			foreach ($workers as $index => $worker) {
				$ready = $directory . '/ready-' . $index;
				while (! is_file($ready) && microtime(true) < $deadline) {
					usleep(1_000);
				}
				$this->assertFileExists($ready, $worker->getErrorOutput());
			}
			touch($directory . '/start');

			$allowed = 0;
			foreach ($workers as $worker) {
				$this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
				$allowed += (int) str_contains($worker->getOutput(), 'allowed');
			}
			$this->assertSame(1, $allowed);
			$this->assertFalse($limiter->peek('client', 2)->isAllowed());
		} finally {
			foreach ($workers as $worker) {
				$worker->stop(0);
			}
			$files->remove($directory);
		}
	}

	public function testExpiredEntriesArePrunedDuringAnUnrelatedRequest(): void
	{
		$limiter = new class () extends InMemoryRateLimiter {
			public function seedAttempts(): void
			{
				$now = time();
				$this->attempts = ['expired' => [$now - 120], 'recent' => [$now]];
				$this->expirations = ['expired' => $now - 60, 'recent' => $now + 60];
			}

			public function recordedClients(): array
			{
				return array_keys($this->attempts);
			}
		};
		$limiter->seedAttempts();

		$this->assertTrue($limiter->attempt('another', 1)->isAllowed());
		$this->assertSame(['recent', 'another'], $limiter->recordedClients());
	}

	public function testFactoryLogsTheInMemoryFallbackWhenLoggingIsConfigured(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$container = $this->createStub(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => $id === LoggerInterface::class);
		$container->method('get')->with(LoggerInterface::class)->willReturn($logger);

		$this->assertInstanceOf(InMemoryRateLimiter::class, (new RateLimiterFactory())($container));
	}
}
