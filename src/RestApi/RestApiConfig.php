<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\Config\Config;

class RestApiConfig extends Config
{
	public static function getDefaults(): array
	{
		return [
			'path' => '/items',
			'resolver' => 'auto',
			'defaultLimit' => 100,
			'maxLimit' => 1000,
			'rateLimit' => 100,
			'rateLimitWindow' => 60,
			'addons' => [],
		];
	}
}
