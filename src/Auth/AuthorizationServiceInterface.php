<?php

declare(strict_types=1);

namespace ON\Auth;

/**
 * Marker interface for application-defined authorization services.
 *
 * Overnight intentionally does not prescribe an authorization API. Applications
 * are free to expose ACLs, policies, helper methods, or any other permission
 * model that best fits their needs.
 */
interface AuthorizationServiceInterface
{
}
