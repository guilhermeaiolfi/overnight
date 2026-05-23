<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

trait AuthorizationAwareEventTrait
{
	protected AuthState $authState = AuthState::Pending;
	protected bool $inheritAuthToNested = false;
	protected bool $nestedAuthorizationInherited = false;

	public function allow(bool $nested = false): void
	{
		$this->authState = AuthState::Allowed;

		if ($nested) {
			$this->inheritAuthToNested = true;
		}
	}

	public function shouldInheritAuthToNested(): bool
	{
		return $this->inheritAuthToNested;
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

	public function inheritNestedAuthorization(): void
	{
		$this->nestedAuthorizationInherited = true;
	}

	public function isNestedAuthorizationInherited(): bool
	{
		return $this->nestedAuthorizationInherited;
	}
}
