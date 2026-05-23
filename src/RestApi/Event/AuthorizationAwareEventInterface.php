<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

interface AuthorizationAwareEventInterface
{
	public function allow(bool $nested = false): void;

	public function requireAuthentication(): void;

	public function forbid(): void;

	public function getAuthState(): AuthState;

	public function shouldInheritAuthToNested(): bool;

	public function inheritNestedAuthorization(): void;

	public function isNestedAuthorizationInherited(): bool;
}
