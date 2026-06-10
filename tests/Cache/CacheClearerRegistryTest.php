<?php

declare(strict_types=1);

namespace Tests\ON\Cache;

use ON\Cache\CacheClearerDefinition;
use ON\Cache\CacheClearerRegistry;
use ON\Cache\Exception\DuplicateCacheClearerException;
use PHPUnit\Framework\TestCase;

final class CacheClearerRegistryTest extends TestCase
{
	public function testStoresDefinitionsByName(): void
	{
		$registry = new CacheClearerRegistry();
		$definition = new CacheClearerDefinition('cache', 'Cache', function (): void {
		});

		$registry->add($definition);

		$this->assertTrue($registry->has('cache'));
		$this->assertSame($definition, $registry->get('cache'));
		$this->assertSame(['cache' => $definition], $registry->all());
	}

	public function testDuplicateNamesThrow(): void
	{
		$registry = new CacheClearerRegistry();
		$registry->add(new CacheClearerDefinition('cache', 'Cache', function (): void {
		}));

		$this->expectException(DuplicateCacheClearerException::class);

		$registry->add(new CacheClearerDefinition('cache', 'Other Cache', function (): void {
		}));
	}

	public function testAllSortsByPriorityThenLabel(): void
	{
		$registry = new CacheClearerRegistry();
		$registry->add(new CacheClearerDefinition('low', 'Low', function (): void {
		}, priority: -10));
		$registry->add(new CacheClearerDefinition('beta', 'Beta', function (): void {
		}, priority: 10));
		$registry->add(new CacheClearerDefinition('alpha', 'Alpha', function (): void {
		}, priority: 10));

		$this->assertSame(['alpha', 'beta', 'low'], array_keys($registry->all()));
	}
}
