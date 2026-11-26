<?php

declare(strict_types=1);

namespace Tests\ON\Swoole;

use Laminas\Diactoros\ServerRequest;
use ON\Swoole\SwooleRequestStack;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine;

final class SwooleRequestStackTest extends TestCase
{
	public function testThrowsWhenSwooleExtensionIsUnavailable(): void
	{
		if (class_exists(Coroutine::class)) {
			$this->markTestSkipped('Swoole extension is installed in this environment.');
		}

		$stack = new SwooleRequestStack();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('SwooleRequestStack requires the Swoole extension.');

		$stack->getCurrentRequest();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testIsolatesStacksPerCoroutineAndFreesOnCoroutineExitViaContext(): void
	{
		eval(<<<'PHP'
namespace Swoole {
	class Coroutine
	{
		public static int $cid = -1;
		/** @var array<int, \ArrayObject<string, mixed>> */
		public static array $contexts = [];

		public static function getCid(): int
		{
			return self::$cid;
		}

		public static function getContext(): ?\ArrayObject
		{
			if (self::$cid <= 0) {
				return null;
			}

			return self::$contexts[self::$cid] ??= new \ArrayObject();
		}
	}
}
PHP);

		$stackA = new SwooleRequestStack();
		$stackB = new SwooleRequestStack();

		$req1 = new ServerRequest([], [], '/coro-1', 'GET');
		$req2 = new ServerRequest([], [], '/coro-2', 'GET');
		$req1B = new ServerRequest([], [], '/coro-1-b', 'GET');

		// Coroutine 1 pushes onto both stackA and stackB
		Coroutine::$cid = 1;
		$stackA->push($req1);
		$stackB->push($req1B);

		// Coroutine 2 pushes onto stackA
		Coroutine::$cid = 2;
		$stackA->push($req2);

		// Verify isolation between coroutines and stack instances
		$this->assertSame('/coro-2', $stackA->getCurrentRequest()?->getUri()->getPath());
		$this->assertNull($stackB->getCurrentRequest());

		Coroutine::$cid = 1;
		$this->assertSame('/coro-1', $stackA->getCurrentRequest()?->getUri()->getPath());
		$this->assertSame('/coro-1-b', $stackB->getCurrentRequest()?->getUri()->getPath());

		// Simulate coroutine 1 exiting (Swoole frees its context)
		unset(Coroutine::$contexts[1]);

		// Internal $stacks array must remain empty (no unbounded growth / memory leak)
		$stacksProp = new ReflectionProperty(SwooleRequestStack::class, 'stacks');
		$this->assertSame([], $stacksProp->getValue($stackA));
		$this->assertSame([], $stacksProp->getValue($stackB));

		// Coroutine 2 stack is still intact
		Coroutine::$cid = 2;
		$this->assertSame('/coro-2', $stackA->getCurrentRequest()?->getUri()->getPath());
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testFallsBackToDeferredCleanupWhenContextIsUnavailable(): void
	{
		eval(<<<'PHP'
namespace Swoole {
	class Coroutine
	{
		public static int $cid = -1;
		/** @var array<int, list<callable>> */
		public static array $deferred = [];

		public static function getCid(): int
		{
			return self::$cid;
		}

		public static function getContext(): ?\ArrayObject
		{
			return null;
		}

		public static function defer(callable $callback): void
		{
			self::$deferred[self::$cid][] = $callback;
		}

		public static function finishCoroutine(int $cid): void
		{
			foreach (self::$deferred[$cid] ?? [] as $callback) {
				$callback();
			}
			unset(self::$deferred[$cid]);
		}
	}
}
PHP);

		$stack = new SwooleRequestStack();
		$stacksProp = new ReflectionProperty(SwooleRequestStack::class, 'stacks');

		Coroutine::$cid = 42;
		$request = new ServerRequest([], [], '/deferred', 'GET');
		$stack->push($request);

		$this->assertSame('/deferred', $stack->getCurrentRequest()?->getUri()->getPath());
		$this->assertArrayHasKey(42, $stacksProp->getValue($stack));
		$this->assertCount(1, Coroutine::$deferred[42]);

		// Simulate coroutine exit running deferred callbacks
		Coroutine::finishCoroutine(42);

		$this->assertSame([], $stacksProp->getValue($stack));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testOutsideCoroutineUsesFallbackStackWithoutLeakingStacksArray(): void
	{
		eval(<<<'PHP'
namespace Swoole {
	class Coroutine
	{
		public static int $cid = -1;

		public static function getCid(): int
		{
			return self::$cid;
		}

		public static function getContext(): ?\ArrayObject
		{
			return null;
		}
	}
}
PHP);

		$stack = new SwooleRequestStack();
		$request = new ServerRequest([], [], '/outside', 'GET');

		Coroutine::$cid = -1;
		$seenPath = $stack->beginRequest($request, static function () use ($stack): ?string {
			return $stack->getCurrentRequest()?->getUri()->getPath();
		});

		$this->assertSame('/outside', $seenPath);
		$this->assertNull($stack->getCurrentRequest());

		$stacksProp = new ReflectionProperty(SwooleRequestStack::class, 'stacks');
		$this->assertSame([], $stacksProp->getValue($stack));
	}
}
