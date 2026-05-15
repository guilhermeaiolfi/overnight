<?php

declare(strict_types=1);

namespace ON\Router;

function php_sapi_name(): string
{
	return $GLOBALS['on_router_test_php_sapi_name'] ?? \php_sapi_name();
}

namespace Tests\ON\Router;

use Laminas\Diactoros\ServerRequest;
use ON\Router\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
	protected function tearDown(): void
	{
		unset($GLOBALS['on_router_test_php_sapi_name']);
	}

	public function testDetectBaseUrlReturnsRootOnCli(): void
	{
		$GLOBALS['on_router_test_php_sapi_name'] = 'cli';

		$this->assertSame('/', Router::detectBaseUrl());
	}

	public function testDetectBaseUrlUsesScriptDirectoryWhenRequestUriStartsWithIt(): void
	{
		$GLOBALS['on_router_test_php_sapi_name'] = 'fpm-fcgi';
		$request = new ServerRequest(serverParams: [
			'SCRIPT_NAME' => '/subdir/public/index.php',
			'REQUEST_URI' => '/subdir/public/posts/list',
		]);

		$this->assertSame('/subdir/public', Router::detectBaseUrl($request));
	}

	public function testDetectBaseUrlFallsBackToParentDirectoryWhenPublicIsNotInRequestUri(): void
	{
		$GLOBALS['on_router_test_php_sapi_name'] = 'fpm-fcgi';
		$request = new ServerRequest(serverParams: [
			'SCRIPT_NAME' => '/subdir/public/index.php',
			'REQUEST_URI' => '/subdir/posts/list',
		]);

		$this->assertSame('/subdir', Router::detectBaseUrl($request));
	}

	public function testNormalizePathRemovesTrailingSlashFromDetectedBaseUrl(): void
	{
		$GLOBALS['on_router_test_php_sapi_name'] = 'fpm-fcgi';
		$request = new ServerRequest(serverParams: [
			'SCRIPT_NAME' => '/subdir/public/index.php',
			'REQUEST_URI' => '/subdir/posts/list',
		]);

		$this->assertSame('/subdir', Router::normalizePath(Router::detectBaseUrl($request) . '/'));
	}
}
