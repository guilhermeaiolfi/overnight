<?php

declare(strict_types=1);

namespace ON\Auth\Storage;

use Laminas\Session\Container as SessionContainer;
use Laminas\Session\ManagerInterface as SessionManager;

class LaminasSessionStorage implements StorageInterface
{
	/**
	 * Default session namespace
	 */
	public const NAMESPACE_DEFAULT = 'ONAuth';

	/**
	 * Default session object member name
	 */
	public const MEMBER_DEFAULT = 'storage';

	/**
	 * Object to proxy $_SESSION storage
	 */
	protected SessionContainer $session;

	/**
	 * Session namespace
	 */
	protected string $namespace = self::NAMESPACE_DEFAULT;

	/**
	 * Session object member
	 */
	protected string $member = self::MEMBER_DEFAULT;

	/**
	 * Sets session storage options and initializes session namespace object
	 *
	 */
	public function __construct(
		?string $namespace = null,
		?string $member = null,
		?SessionManager $manager = null
	) {
		if ($namespace !== null) {
			$this->namespace = $namespace;
		}
		if ($member !== null) {
			$this->member = $member;
		}
		$this->session = new SessionContainer($this->namespace, $manager);
	}

	/**
	 * Returns the session namespace
	 */
	public function getNamespace(): string
	{
		return $this->namespace;
	}

	/**
	 * Returns the name of the session object member
	 */
	public function getMember(): string
	{
		return $this->member;
	}

	public function isEmpty(): bool
	{
		return ! isset($this->session->{$this->member});
	}

	public function read(): mixed
	{
		return $this->session->{$this->member};
	}

	public function write(mixed $contents): void
	{
		$this->session->{$this->member} = $contents;
	}

	public function clear(): void
	{
		unset($this->session->{$this->member});
	}
}
