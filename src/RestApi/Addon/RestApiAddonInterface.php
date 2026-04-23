<?php

declare(strict_types=1);

namespace ON\RestApi\Addon;

use ON\RestApi\RestApiService;

/**
 * Interface for optional REST API addons.
 *
 * Addons are resolved from the container via $container->get(AddonClass::class),
 * so they receive all dependencies via constructor injection.
 *
 * If the addon also implements MiddlewareInterface, it will be piped
 * at the REST API base path automatically.
 */
interface RestApiAddonInterface
{
	/**
	 * Called during extension setup with addon-specific options.
	 */
	public function register(RestApiService $restApi, array $options = []): void;
}
