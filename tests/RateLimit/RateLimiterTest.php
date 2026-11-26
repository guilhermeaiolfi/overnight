<?php

declare(strict_types=1);

namespace Tests\ON\RateLimit;

use InvalidArgumentException;
use ON\Cache\Cache;
use ON\RateLimit\CacheRateLimiter;
use ON\RateLimit\Container\RateLimiterFactory;
use ON\RateLimit\InMemoryRateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;

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
