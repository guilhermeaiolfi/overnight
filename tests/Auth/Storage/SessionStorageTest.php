<?php

declare(strict_types=1);

namespace Tests\ON\Auth\Storage;

use ON\Auth\Result;
use ON\Auth\Storage\SessionStorage;
use ON\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

class SessionStorageTest extends TestCase
{
	public function testUsesDefaultNamespaceWhenNoneIsProvided(): void
	{
		$session = $this->createMock(SessionInterface::class);
		$session->expects($this->once())
			->method('has')
			->with(SessionStorage::NAMESPACE_DEFAULT)
			->willReturn(false);

		$storage = new SessionStorage($session);

		$this->assertTrue($storage->isEmpty());
	}

	public function testReadsAndWritesConfiguredNamespace(): void
	{
		$session = $this->createMock(SessionInterface::class);
		$session->expects($this->once())
			->method('set')
			->with('custom-auth', ['id' => 5]);
		$session->expects($this->once())
			->method('get')
			->with('custom-auth')
			->willReturn(['id' => 5]);

		$storage = new SessionStorage($session, 'custom-auth');
		$storage->write(['id' => 5]);

		$this->assertSame(['id' => 5], $storage->read());
	}

	public function testLoginRegeneratesSessionId(): void
	{
		$session = $this->createMock(SessionInterface::class);
		$session->expects($this->once())
			->method('regenerateId')
			->with(true)
			->willReturn(true);

		$storage = new SessionStorage($session);
		$storage->onLogin();
	}

	public function testLogoutRegeneratesSessionId(): void
	{
		$session = $this->createMock(SessionInterface::class);
		$session->expects($this->once())
			->method('regenerateId')
			->with(true)
			->willReturn(true);

		$storage = new SessionStorage($session);
		$storage->onLogout();
	}
}
