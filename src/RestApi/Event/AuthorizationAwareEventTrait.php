<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

trait AuthorizationAwareEventTrait
{
	protected AuthState $authState = AuthState::Pending;

	public function allow(): void
	{
		$this->authState = AuthState::Allowed;
	}

	public function requireAuthentication(): void
	{
		$this->authState = AuthState::Unauthenticated;
	}

	public function forbid(): void
	{
		$this->authState = AuthState::Forbidden;
	}

	public function getAuthState(): AuthState
	{
		return $this->authState;
	}
}
