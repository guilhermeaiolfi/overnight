<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

interface AuthorizationAwareEventInterface
{
	public function allow(): void;

	public function requireAuthentication(): void;

	public function forbid(): void;

	public function getAuthState(): AuthState;
}
