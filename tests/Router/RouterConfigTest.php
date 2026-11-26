<?php

declare(strict_types=1);

namespace Tests\ON\Router;

use function array_key_exists;
use ON\Router\RouterConfig;
use PHPUnit\Framework\TestCase;

final class RouterConfigTest extends TestCase
{
	private bool $hadAppDebug = false;
	private mixed $previousAppDebug = null;

	protected function setUp(): void
	{
		$this->hadAppDebug = array_key_exists('APP_DEBUG', $_ENV);
		$this->previousAppDebug = $_ENV['APP_DEBUG'] ?? null;
	}

	protected function tearDown(): void
	{
		if ($this->hadAppDebug) {
			$_ENV['APP_DEBUG'] = $this->previousAppDebug;
		} else {
			unset($_ENV['APP_DEBUG']);
		}
	}

	public function testCacheIsEnabledWhenAppDebugIsStringFalse(): void
	{
		$_ENV['APP_DEBUG'] = 'false';

		$defaults = RouterConfig::getDefaults();

		$this->assertTrue($defaults['cache_enabled']);
	}

	public function testCacheIsEnabledWhenAppDebugIsUnsetOrFalsey(): void
	{
		unset($_ENV['APP_DEBUG']);
		$this->assertTrue(RouterConfig::getDefaults()['cache_enabled']);

		$_ENV['APP_DEBUG'] = '0';
		$this->assertTrue(RouterConfig::getDefaults()['cache_enabled']);

		$_ENV['APP_DEBUG'] = false;
		$this->assertTrue(RouterConfig::getDefaults()['cache_enabled']);
	}

	public function testCacheIsDisabledWhenAppDebugIsTruthy(): void
	{
		$_ENV['APP_DEBUG'] = 'true';
		$this->assertFalse(RouterConfig::getDefaults()['cache_enabled']);

		$_ENV['APP_DEBUG'] = '1';
		$this->assertFalse(RouterConfig::getDefaults()['cache_enabled']);

		$_ENV['APP_DEBUG'] = true;
		$this->assertFalse(RouterConfig::getDefaults()['cache_enabled']);
	}
}
