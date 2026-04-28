<?php

declare(strict_types=1);

namespace ON\Auth;

/**
 * Optional base class for application-defined authorization services.
 *
 * Overnight does not provide a built-in authorization engine. Instead,
 * pages and controllers expose permission hooks that may call whatever
 * application-specific authorization API they need.
 */
class AuthorizationService implements AuthorizationServiceInterface
{
}
