<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\Container\Executor\ExecutorInterface;
use ON\Data\Definition\Registry;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Hook\RestHooks;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;
use stdClass;

final class RestHookDispatcherTest extends TestCase
{
	private function createDispatcher(): RestHookDispatcher
	{
		$container = new class () implements ContainerInterface {
			public function get(string $id): mixed
			{
				throw new RuntimeException("Unknown service {$id}");
			}

			public function has(string $id): bool
			{
				return false;
			}
		};

		$executor = new class () implements ExecutorInterface {
			public function execute($callableOrMethodStr, array $args = []): mixed
			{
				return $callableOrMethodStr($args['payload']);
			}

			public function getContainer(): ?ContainerInterface
			{
				return null;
			}
		};

		return new RestHookDispatcher($container, $executor);
	}

	public function testDispatchesHooksInPriorityOrderAndCachesSortedDefinitions(): void
	{
		$registry = new Registry();
		$registry->collection('post')->end();
		$collection = $registry->getCollection('post');

		$order = [];
		RestHooks::for($collection)
			->on('before.create', static function () use (&$order): void {
				$order[] = 'low';
			}, -5)
			->on('before.create', static function () use (&$order): void {
				$order[] = 'normal-first';
			}, 10)
			->on('before.create', static function () use (&$order): void {
				$order[] = 'high';
			}, 50)
			->on('before.create', static function () use (&$order): void {
				$order[] = 'normal-second';
			}, 10);

		$dispatcher = $this->createDispatcher();
		$cacheProperty = new ReflectionProperty(RestHookDispatcher::class, 'sortedCache');

		$dispatcher->dispatch($collection, 'before.create', new stdClass(), false);
		$this->assertSame(['high', 'normal-first', 'normal-second', 'low'], $order);

		$cache = $cacheProperty->getValue($dispatcher);
		$this->assertTrue(isset($cache[$collection]['before.create']));

		// Dispatch again and verify cached order is reused cleanly
		$order = [];
		$dispatcher->dispatch($collection, 'before.create', new stdClass(), false);
		$this->assertSame(['high', 'normal-first', 'normal-second', 'low'], $order);
	}

	public function testReSortsWhenNewHooksAreRegisteredAfterInitialDispatch(): void
	{
		$registry = new Registry();
		$registry->collection('post')->end();
		$collection = $registry->getCollection('post');

		$order = [];
		RestHooks::for($collection)
			->on('before.create', static function () use (&$order): void {
				$order[] = 'first';
			}, 10)
			->on('before.create', static function () use (&$order): void {
				$order[] = 'second';
			}, 20);

		$dispatcher = $this->createDispatcher();
		$dispatcher->dispatch($collection, 'before.create', new stdClass(), false);
		$this->assertSame(['second', 'first'], $order);

		// Register a higher-priority hook after the initial dispatch
		RestHooks::for($collection)
			->on('before.create', static function () use (&$order): void {
				$order[] = 'highest';
			}, 100);

		$order = [];
		$dispatcher->dispatch($collection, 'before.create', new stdClass(), false);
		$this->assertSame(['highest', 'second', 'first'], $order);
	}

	public function testHandlesEmptyAndSingleHookSlots(): void
	{
		$registry = new Registry();
		$registry->collection('post')->end();
		$collection = $registry->getCollection('post');

		$dispatcher = $this->createDispatcher();
		$payload = new stdClass();

		// Empty slot
		$this->assertSame($payload, $dispatcher->dispatch($collection, 'missing.slot', $payload, false));

		// Single hook slot
		$called = false;
		RestHooks::for($collection)->on('after.delete', static function () use (&$called): void {
			$called = true;
		});

		$dispatcher->dispatch($collection, 'after.delete', $payload, false);
		$this->assertTrue($called);
	}
}
