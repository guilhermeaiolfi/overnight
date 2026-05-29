<?php

declare(strict_types=1);

namespace Tests\ON\Mapper;

use ON\Mapper\ConversionGateway;
use ON\Mapper\Structural\MapperInterface;
use ON\Mapper\Structural\MapperRegistry;
use ON\Mapper\Structural\MappingContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class MapperRegistryTest extends TestCase
{
	protected function tearDown(): void
	{
		LazyRejectingMapper::$canMapCalls = 0;
		LazyRejectingMapper::$constructCalls = 0;
		LazyAcceptingMapper::$canMapCalls = 0;
		LazyAcceptingMapper::$constructCalls = 0;
	}

	public function testRegisterRejectsDuplicateMapperClass(): void
	{
		$registry = new MapperRegistry();
		$registry->register(LazyAcceptingMapper::class);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('already registered');

		$registry->register(LazyAcceptingMapper::class);
	}

	public function testMapUsesStaticCapabilityBeforeResolvingMapper(): void
	{
		$container = new LazyMapperContainer();
		$registry = new MapperRegistry($container);
		$registry->register(LazyRejectingMapper::class);
		$registry->register(LazyAcceptingMapper::class);

		$result = $registry->map('source', 'target', $this->context());

		$this->assertSame('mapped:source', $result);
		$this->assertSame(1, LazyRejectingMapper::$canMapCalls);
		$this->assertSame(0, LazyRejectingMapper::$constructCalls);
		$this->assertSame(1, LazyAcceptingMapper::$canMapCalls);
		$this->assertSame(1, LazyAcceptingMapper::$constructCalls);
		$this->assertSame(1, $container->gets[LazyAcceptingMapper::class]);
	}

	public function testResolvedMapperIsCached(): void
	{
		$container = new LazyMapperContainer();
		$registry = new MapperRegistry($container);
		$registry->register(LazyAcceptingMapper::class);

		$registry->map('first', 'target', $this->context());
		$registry->map('second', 'target', $this->context());

		$this->assertSame(1, LazyAcceptingMapper::$constructCalls);
		$this->assertSame(1, $container->gets[LazyAcceptingMapper::class]);
	}

	public function testReplaceMovesMapperAndClearsCachedInstance(): void
	{
		$container = new LazyMapperContainer();
		$registry = new MapperRegistry($container);
		$registry->register(LazyAcceptingMapper::class);
		$registry->map('first', 'target', $this->context());

		$registry->replace(LazyAcceptingMapper::class);
		$registry->map('second', 'target', $this->context());

		$this->assertSame([LazyAcceptingMapper::class], $registry->classes());
		$this->assertSame(2, LazyAcceptingMapper::$constructCalls);
		$this->assertSame(2, $container->gets[LazyAcceptingMapper::class]);
	}

	private function context(?string $mapperClass = null): MappingContext
	{
		return new MappingContext(ConversionGateway::createDefault(), mapperClass: $mapperClass);
	}
}

final class LazyMapperContainer implements ContainerInterface
{
	/** @var array<class-string, int> */
	public array $gets = [];

	public function get(string $id): mixed
	{
		$this->gets[$id] = ($this->gets[$id] ?? 0) + 1;

		return new $id();
	}

	public function has(string $id): bool
	{
		return is_subclass_of($id, MapperInterface::class);
	}
}

final class LazyRejectingMapper implements MapperInterface
{
	public static int $canMapCalls = 0;
	public static int $constructCalls = 0;

	public function __construct()
	{
		self::$constructCalls++;
	}

	public static function canMap(mixed $from, mixed $to, MappingContext $context): bool
	{
		self::$canMapCalls++;

		return false;
	}

	public function map(mixed $from, mixed $to, MappingContext $context): mixed
	{
		return null;
	}

	public static function defaultRepresentations(): array
	{
		return [];
	}
}

final class LazyAcceptingMapper implements MapperInterface
{
	public static int $canMapCalls = 0;
	public static int $constructCalls = 0;

	public function __construct()
	{
		self::$constructCalls++;
	}

	public static function canMap(mixed $from, mixed $to, MappingContext $context): bool
	{
		self::$canMapCalls++;

		return $to === 'target';
	}

	public function map(mixed $from, mixed $to, MappingContext $context): mixed
	{
		return 'mapped:' . $from;
	}

	public static function defaultRepresentations(): array
	{
		return [];
	}
}
