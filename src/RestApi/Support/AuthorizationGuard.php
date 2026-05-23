<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
use ON\RestApi\Error\RestApiError;

final class AuthorizationGuard
{
	public static function assert(object $event): void
	{
		if (!$event instanceof AuthorizationAwareEventInterface) {
			return;
		}

		if ($event->isNestedAuthorizationInherited() && $event->getAuthState() === AuthState::Pending) {
			$event->allow();
		}

		match ($event->getAuthState()) {
			AuthState::Allowed => null,
			AuthState::Unauthenticated => throw RestApiError::unauthenticated(),
			AuthState::Forbidden, AuthState::Pending => throw RestApiError::forbidden(),
		};
	}
}
