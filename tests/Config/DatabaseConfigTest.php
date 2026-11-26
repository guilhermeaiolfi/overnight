<?php

declare(strict_types=1);

namespace Tests\ON\Config;

use ON\DB\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
	public function testGetDefaultReturnsNullWhenNoDefaultDatabaseIsConfigured(): void
	{
		$config = new DatabaseConfig();

		$this->assertFalse($config->hasDefault());
		$this->assertNull($config->getDefault());
	}

	public function testGetDefaultReturnsDefaultDatabaseConfiguration(): void
	{
		$config = new DatabaseConfig();
		$config->addDatabase('primary', 'sqlite::memory:');

		$this->assertTrue($config->hasDefault());
		$this->assertSame('primary', $config->getDefaultName());

		$default = $config->getDefault();
		$this->assertIsArray($default);
		$this->assertSame('sqlite::memory:', $default['dsn']);
	}

	public function testGetDefaultRespectsSetDefaultOverride(): void
	{
		$config = new DatabaseConfig();
		$config->addDatabase('primary', 'sqlite::memory:');
		$config->addDatabase('replica', 'sqlite:/tmp/replica.sqlite');
		$config->setDefault('replica');

		$this->assertSame('replica', $config->getDefaultName());

		$default = $config->getDefault();
		$this->assertIsArray($default);
		$this->assertSame('sqlite:/tmp/replica.sqlite', $default['dsn']);
	}
}
