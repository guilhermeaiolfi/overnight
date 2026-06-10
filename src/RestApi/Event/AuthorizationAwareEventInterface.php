<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\RestApi\Error\RestApiError;

interface AuthorizationAwareEventInterface
{
	public function allow(bool $nested = false): void;

	public function requireAuthentication(?RestApiError $error = null): void;

	public function forbid(?RestApiError $error = null): void;

	public function getAuthState(): AuthState;

	public function shouldInheritAuthToNested(): bool;

	public function inheritNestedAuthorization(): void;

	public function isNestedAuthorizationInherited(): bool;

	public function getAuthorizationError(): ?RestApiError;
}
