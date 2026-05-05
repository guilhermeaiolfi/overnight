<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\Config\Config;

class RestApiConfig extends Config
{
	public string $endpointUri = '/items';
	public string $resolver = 'auto';
	public int $defaultLimit = 100;
	public int $maxLimit = 1000;
	public int $rateLimit = 100;
	public int $rateLimitWindow = 60;
	public array $addons = [];
}
