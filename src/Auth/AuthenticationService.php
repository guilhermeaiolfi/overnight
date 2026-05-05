<?php

declare(strict_types=1);

namespace ON\Auth;

use Exception;
use ON\Auth\Event\AuthenticationFailedEvent;
use ON\Auth\Event\AuthenticationSucceededEvent;
use ON\Auth\Event\LogoutEvent;
use ON\Auth\Storage\AuthLifecycleStorageInterface;
use ON\Auth\Storage\StorageInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

class AuthenticationService implements AuthenticationServiceInterface
{
	public function __construct(
		protected ?StorageInterface $storage = null,
		protected ?AuthenticatorInterface $authenticator = null,
		protected ?EventDispatcherInterface $eventDispatcher = null
	) {
		if (null !== $storage) {
			$this->setStorage($storage);
		}
		if (null !== $authenticator) {
			$this->setAuthenticator($authenticator);
		}
	}

	/**
	 * Returns the authentication adapter
	 *
	 * The adapter does not have a default if the storage adapter has not been set.
	 *
	 */
	public function getAuthenticator(): ?AuthenticatorInterface
	{
		return $this->authenticator;
	}

	/**
	 * Sets the authentication adapter
	 *
	 */
	public function setAuthenticator(authenticatorInterface $authenticator): self
	{
		$this->authenticator = $authenticator;

		return $this;
	}

	/**
	 * Returns the persistent storage handler
	 *
	 * Session storage is used by default unless a different storage adapter has been set.
	 */
	public function getStorage(): StorageInterface
	{
		if ($this->storage === null) {
			throw new RuntimeException(
				'Authentication storage has not been configured.'
			);
		}

		return $this->storage;
	}

	/**
	 * Sets the persistent storage handler
	 *
	 * @return self Provides a fluent interface
	 */
	public function setStorage(StorageInterface $storage): self
	{
		$this->storage = $storage;

		return $this;
	}

	/**
	 * Authenticates against the supplied adapter
	 *
	 */
	public function authenticate(?AuthenticatorInterface $authenticator = null): Result
	{
		if (! $authenticator) {
			if (! $authenticator = $this->getAuthenticator()) {
				throw new Exception(
					'An authenticator must be set or passed prior to calling authenticate()'
				);
			}
		}
		$result = $authenticator->authenticate();

		/**
		 * Laminas-7546 - prevent multiple successive calls from storing inconsistent results
		 * Ensure storage has clean state
		 */
		if ($this->hasIdentity()) {
			$this->logout();
		}

		if ($result->isValid()) {
			$storage = $this->getStorage();
			$storage->write($result->getIdentity());

			if ($storage instanceof AuthLifecycleStorageInterface) {
				$storage->onLogin();
			}

			$this->eventDispatcher?->dispatch(
				new AuthenticationSucceededEvent(
					$result,
					$result->getIdentity(),
					$authenticator
				)
			);

			return $result;
		}

		$this->eventDispatcher?->dispatch(
			new AuthenticationFailedEvent($result, $authenticator)
		);

		return $result;
	}

	/**
	 * Returns true if and only if an identity is available from storage
	 *
	 */
	public function hasIdentity(): bool
	{
		return ! $this->getStorage()->isEmpty();
	}

	/**
	 * Returns the identity from storage or null if no identity is available
	 *
	 * @return mixed
	 */
	public function getIdentity(): mixed
	{
		$storage = $this->getStorage();
		if ($storage->isEmpty()) {
			return null;
		}

		return $storage->read();
	}

	/*
	*  Just alias to ->clearIdentity();
	*/
	public function logout(): void
	{
		$identity = $this->getIdentity();
		$storage = $this->getStorage();
		$storage->clear();

		if ($storage instanceof AuthLifecycleStorageInterface) {
			$storage->onLogout();
		}

		$this->eventDispatcher?->dispatch(new LogoutEvent($identity));
	}
}
