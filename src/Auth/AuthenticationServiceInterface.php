<?php

declare(strict_types=1);

namespace ON\Auth;

interface AuthenticationServiceInterface
{
	/**
	 * Authenticates and provides an authentication result
	 *
	 */
	public function authenticate(): Result;

	/**
	 * Returns true if and only if an identity is available
	 *
	 */
	public function hasIdentity(): bool;

	/**
	 * Returns the authenticated identity or null if no identity is available
	 *
	 */
	public function getIdentity(): mixed;

	/**
	 * Clears the identity
	 *
	 */
	public function logout(): void;

	public function getAuthenticator(): ?AuthenticatorInterface;
}
