<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\RestApi\Error\RestApiError;

trait AuthorizationAwareEventTrait
{
	protected AuthState $authState = AuthState::Pending;
	protected bool $inheritAuthToNested = false;
	protected bool $nestedAuthorizationInherited = false;
	protected ?RestApiError $authorizationError = null;

	public function allow(bool $nested = false): void
	{
		$this->authState = AuthState::Allowed;
		$this->authorizationError = null;

		if ($nested) {
			$this->inheritAuthToNested = true;
		}
	}

	public function shouldInheritAuthToNested(): bool
	{
		return $this->inheritAuthToNested;
	}

	public function requireAuthentication(?RestApiError $error = null): void
	{
		$this->authState = AuthState::Unauthenticated;
		$this->authorizationError = $error;
	}

	public function forbid(?RestApiError $error = null): void
	{
		$this->authState = AuthState::Forbidden;
		$this->authorizationError = $error;
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

	public function getAuthorizationError(): ?RestApiError
	{
		return $this->authorizationError;
	}
}
