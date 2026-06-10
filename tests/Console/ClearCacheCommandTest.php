<?php

declare(strict_types=1);

namespace Tests\ON\Console;

use ON\Cache\CacheClearerDefinition;
use ON\Cache\CacheClearerRegistry;
use ON\Console\Command\ClearCacheCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ClearCacheCommandTest extends TestCase
{
	public function testListPrintsRegisteredClearers(): void
	{
		$registry = new CacheClearerRegistry();
		$registry->add(new CacheClearerDefinition('cache', 'CacheInterface', function (): void {
		}, description: 'Clears default cache.'));

		$tester = new CommandTester(new ClearCacheCommand($registry, new ArrayContainer()));

		$this->assertSame(Command::SUCCESS, $tester->execute(['--list' => true]));
		$this->assertStringContainsString('cache - CacheInterface (Clears default cache.)', $tester->getDisplay());
	}

	public function testAllInvokesIncludedClearersInPriorityOrder(): void
	{
		$calls = [];
		$registry = new CacheClearerRegistry();
		$registry->add(new CacheClearerDefinition('low', 'Low', function () use (&$calls): void {
			$calls[] = 'low';
		}, priority: -10));
		$registry->add(new CacheClearerDefinition('skipped', 'Skipped', function () use (&$calls): void {
			$calls[] = 'skipped';
		}, priority: 100, includedInAll: false));
		$registry->add(new CacheClearerDefinition('high', 'High', function () use (&$calls): void {
			$calls[] = 'high';
		}, priority: 10));

		$tester = new CommandTester(new ClearCacheCommand($registry, new ArrayContainer()));

		$this->assertSame(Command::SUCCESS, $tester->execute(['--all' => true]));
		$this->assertSame(['high', 'low'], $calls);
	}

	public function testNamedClearersInvokeOnlyRequestedClearers(): void
	{
		$calls = [];
		$registry = new CacheClearerRegistry();
		$registry->add(new CacheClearerDefinition('cache', 'Cache', function () use (&$calls): void {
			$calls[] = 'cache';
		}));
		$registry->add(new CacheClearerDefinition('discovery', 'Discovery', function () use (&$calls): void {
			$calls[] = 'discovery';
		}));

		$tester = new CommandTester(new ClearCacheCommand($registry, new ArrayContainer()));

		$this->assertSame(Command::SUCCESS, $tester->execute(['clearers' => ['discovery']]));
		$this->assertSame(['discovery'], $calls);
	}

	public function testFailingClearerReturnsFailure(): void
	{
		$registry = new CacheClearerRegistry();
		$registry->add(new CacheClearerDefinition('broken', 'Broken', function (): void {
			throw new RuntimeException('Nope');
		}));

		$tester = new CommandTester(new ClearCacheCommand($registry, new ArrayContainer()));

		$this->assertSame(Command::FAILURE, $tester->execute(['clearers' => ['broken']]));
		$this->assertStringContainsString('Failed clearing broken: Nope', $tester->getDisplay());
	}

	public function testUnknownClearerReturnsFailure(): void
	{
		$registry = new CacheClearerRegistry();
		$tester = new CommandTester(new ClearCacheCommand($registry, new ArrayContainer()));

		$this->assertSame(Command::FAILURE, $tester->execute(['clearers' => ['missing']]));
		$this->assertStringContainsString('Cache clearer "missing" is not registered.', $tester->getDisplay());
	}
}

final class ArrayContainer implements ContainerInterface
{
	public function get(string $id): mixed
	{
		throw new RuntimeException(sprintf('Service "%s" is not available.', $id));
	}

	public function has(string $id): bool
	{
		return false;
	}
}
