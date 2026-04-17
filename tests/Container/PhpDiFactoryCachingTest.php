<?php

declare(strict_types=1);

namespace Tests\ON\Container;

use DI\Container;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;

use function DI\factory;

/**
 * Verifies that PHP-DI caches the result of factory definitions on get(),
 * meaning the same instance is returned on subsequent calls.
 *
 * This matters for ViewInterface registration: because get() caches,
 * we register it as an alias (autowired) rather than a factory, since
 * each page is itself resolved once per request and gets its own View.
 */
final class PhpDiFactoryCachingTest extends TestCase
{
	public function testGetOnFactoryReturnsSameInstance(): void
	{
		$builder = new ContainerBuilder();
		$builder->addDefinitions([
			'counter' => factory(function () {
				return new \stdClass();
			}),
		]);

		$container = $builder->build();

		$first = $container->get('counter');
		$second = $container->get('counter');

		$this->assertSame($first, $second, 'get() on a factory should return the cached (same) instance');
	}

	public function testSetClosureAlsoCachesOnGet(): void
	{
		$builder = new ContainerBuilder();
		$container = $builder->build();

		$callCount = 0;
		$container->set('widget', function () use (&$callCount) {
			$callCount++;
			return new \stdClass();
		});

		$first = $container->get('widget');
		$second = $container->get('widget');

		$this->assertSame($first, $second, 'set() with a closure should also cache on get()');
		$this->assertSame(1, $callCount, 'Factory closure should only be invoked once');
	}

	public function testMakeCreatesFreshDependenciesToo(): void
	{
		$builder = new ContainerBuilder();
		$builder->useAutowiring(true);
		$builder->addDefinitions([
			'dep' => factory(function () {
				return new \stdClass();
			}),
		]);

		$container = $builder->build();

		// get() caches
		$a = $container->get('dep');
		$b = $container->get('dep');
		$this->assertSame($a, $b);

		// make() creates fresh
		$c = $container->make('dep');
		$this->assertNotSame($a, $c);
	}

	public function testMakeOnConsumerSharesDependencyViaGet(): void
	{
		$builder = new ContainerBuilder();
		$builder->useAutowiring(true);
		$builder->addDefinitions([
			TestServiceInterface::class => factory(function () {
				return new TestService();
			}),
		]);

		$container = $builder->build();

		// make() creates a fresh consumer, but its dependency is resolved via get() — cached
		$consumer1 = $container->make(TestConsumer::class);
		$consumer2 = $container->make(TestConsumer::class);

		$this->assertNotSame($consumer1, $consumer2, 'make() creates fresh consumers');
		// The key question: are the injected dependencies the same or different?
		$this->assertSame(
			$consumer1->service->id,
			$consumer2->service->id,
			'Dependencies injected via make() are still resolved via get() — cached'
		);
	}

	public function testMakeWithParameterOverrideCreatesFreshDependency(): void
	{
		$builder = new ContainerBuilder();
		$builder->useAutowiring(true);
		$builder->addDefinitions([
			TestServiceInterface::class => factory(function () {
				return new TestService();
			}),
		]);

		$container = $builder->build();

		// Passing the dependency explicitly via make() parameters bypasses the cache
		$fresh = new TestService();
		$consumer = $container->make(TestConsumer::class, ['service' => $fresh]);

		$this->assertSame($fresh->id, $consumer->service->id);
	}

	/**
	 * This is the pattern used by ViewFactory: a service that calls
	 * $container->make() internally to always produce fresh instances.
	 * The factory itself is a singleton (cached by get()), but each
	 * create() call returns a new object.
	 */
	public function testFactoryServicePatternCreatesFreshInstances(): void
	{
		$builder = new ContainerBuilder();
		$builder->useAutowiring(true);
		$container = $builder->build();

		// Simulate ViewFactory pattern: a singleton service that calls make()
		$factory = new class($container) {
			public function __construct(private Container $container) {}
			public function create(): TestService {
				return $this->container->make(TestService::class);
			}
		};

		$a = $factory->create();
		$b = $factory->create();

		$this->assertNotSame($a, $b, 'Factory service with make() creates fresh instances');
		$this->assertNotSame($a->id, $b->id, 'Each instance has unique state');
	}
}


interface TestServiceInterface {}

class TestService implements TestServiceInterface
{
	public string $id;

	public function __construct()
	{
		$this->id = uniqid('', true);
	}
}

class TestConsumer
{
	public function __construct(
		public TestServiceInterface $service
	) {}
}
