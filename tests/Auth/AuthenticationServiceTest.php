<?php

declare(strict_types=1);

namespace Tests\ON\Auth;

use ON\Auth\AuthenticationService;
use ON\Auth\AuthenticatorInterface;
use ON\Auth\Event\AuthenticationFailedEvent;
use ON\Auth\Event\AuthenticationSucceededEvent;
use ON\Auth\Event\LogoutEvent;
use ON\Auth\Result;
use ON\Auth\Storage\AuthLifecycleStorageInterface;
use ON\Auth\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

class AuthenticationServiceTest extends TestCase
{
	private function createLifecycleAwareStorageFake(): object
	{
		return new class implements StorageInterface, AuthLifecycleStorageInterface {
			public bool $empty = true;
			public array $writes = [];
			public int $clearCalls = 0;
			public int $loginCalls = 0;
			public int $logoutCalls = 0;

			public function isEmpty(): bool
			{
				return $this->empty;
			}

			public function read(): mixed
			{
				return null;
			}

			public function write(mixed $contents): void
			{
				$this->writes[] = $contents;
				$this->empty = false;
			}

			public function clear(): void
			{
				$this->clearCalls++;
				$this->empty = true;
			}

			public function onLogin(): void
			{
				$this->loginCalls++;
			}

			public function onLogout(): void
			{
				$this->logoutCalls++;
			}
		};
	}

	private function createCollectingDispatcher(array &$events): EventDispatcherInterface
	{
		return new class($events) implements EventDispatcherInterface {
			public function __construct(private array &$events)
			{
			}

			public function dispatch(object $event): object
			{
				$this->events[] = $event;

				return $event;
			}
		};
	}

	public function testAuthenticateThrowsClearExceptionWhenAuthenticatorIsMissing(): void
	{
		$service = new AuthenticationService($this->createMock(StorageInterface::class));

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('An authenticator must be set or passed prior to calling authenticate()');

		$service->authenticate();
	}

	public function testHasIdentityThrowsClearExceptionWhenStorageIsMissing(): void
	{
		$service = new AuthenticationService();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Authentication storage has not been configured.');

		$service->hasIdentity();
	}

	public function testSuccessfulAuthenticationWritesIdentityAndRunsStorageLifecycleHook(): void
	{
		$result = new Result(Result::SUCCESS, ['id' => 123]);
		$storage = $this->createLifecycleAwareStorageFake();
		$events = [];

		$authenticator = $this->createMock(AuthenticatorInterface::class);
		$authenticator->method('authenticate')->willReturn($result);

		$service = new AuthenticationService(
			$storage,
			$authenticator,
			$this->createCollectingDispatcher($events)
		);

		$this->assertSame($result, $service->authenticate());
		$this->assertSame([['id' => 123]], $storage->writes);
		$this->assertSame(1, $storage->loginCalls);
		$this->assertCount(1, $events);
		$this->assertInstanceOf(AuthenticationSucceededEvent::class, $events[0]);
		$this->assertSame(['id' => 123], $events[0]->getIdentity());
		$this->assertSame($result, $events[0]->getResult());
	}

	public function testFailedAuthenticationDoesNotWriteIdentityOrRunLifecycleHook(): void
	{
		$result = new Result(Result::FAILURE_CREDENTIAL_INVALID, null, ['bad credentials']);
		$storage = $this->createLifecycleAwareStorageFake();
		$events = [];

		$authenticator = $this->createMock(AuthenticatorInterface::class);
		$authenticator->method('authenticate')->willReturn($result);

		$service = new AuthenticationService(
			$storage,
			$authenticator,
			$this->createCollectingDispatcher($events)
		);

		$this->assertSame($result, $service->authenticate());
		$this->assertSame([], $storage->writes);
		$this->assertSame(0, $storage->loginCalls);
		$this->assertCount(1, $events);
		$this->assertInstanceOf(AuthenticationFailedEvent::class, $events[0]);
		$this->assertSame($result, $events[0]->getResult());
	}

	public function testLogoutClearsIdentityAndRunsStorageLifecycleHook(): void
	{
		$storage = $this->createLifecycleAwareStorageFake();
		$storage->empty = false;
		$events = [];

		$storageWithIdentity = new class($storage) implements StorageInterface, AuthLifecycleStorageInterface {
			public function __construct(private object $inner)
			{
			}

			public function isEmpty(): bool
			{
				return $this->inner->isEmpty();
			}

			public function read(): mixed
			{
				return ['id' => 5];
			}

			public function write(mixed $contents): void
			{
				$this->inner->write($contents);
			}

			public function clear(): void
			{
				$this->inner->clear();
			}

			public function onLogin(): void
			{
				$this->inner->onLogin();
			}

			public function onLogout(): void
			{
				$this->inner->onLogout();
			}
		};

		$service = new AuthenticationService(
			$storageWithIdentity,
			null,
			$this->createCollectingDispatcher($events)
		);
		$service->logout();

		$this->assertSame(1, $storage->clearCalls);
		$this->assertSame(1, $storage->logoutCalls);
		$this->assertCount(1, $events);
		$this->assertInstanceOf(LogoutEvent::class, $events[0]);
		$this->assertSame(['id' => 5], $events[0]->getIdentity());
	}

	public function testAuthenticateLogsOutExistingIdentityBeforeWritingNewIdentity(): void
	{
		$result = new Result(Result::SUCCESS, 'new-user');
		$storage = $this->createLifecycleAwareStorageFake();
		$storage->empty = false;

		$authenticator = $this->createMock(AuthenticatorInterface::class);
		$authenticator->method('authenticate')->willReturn($result);

		$service = new AuthenticationService($storage, $authenticator);
		$service->authenticate();

		$this->assertSame(1, $storage->clearCalls);
		$this->assertSame(1, $storage->logoutCalls);
		$this->assertSame(['new-user'], $storage->writes);
		$this->assertSame(1, $storage->loginCalls);
	}
}
