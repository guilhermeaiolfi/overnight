<?php

declare(strict_types=1);

namespace ON\Config;

use Exception;
use ON\Router\Route;

class RouterConfig extends Config
{
	public bool $done = false;
	public static function getDefaults(): array
	{
		return [
			"cache_enabled" => ! $_ENV["APP_DEBUG"], // true|false
			"cache_file" => 'var/cache/router.php.cache', // optional
			"baseHref" => null,
			"routes" => [],
		];
	}

	public function addRoute(mixed $path_or_obj, string $action = null, ?array $methods = ["GET"], $route_name = null)
	{
		if ($this->done) {
			throw new Exception("RouterConfig are use only to add routes at config. Use the router directly after that.");
		}
		if ($path_or_obj instanceof Route) {
			$this->items["routes"][] = $path_or_obj;
		} else {
			$this->items["routes"][] = [
				$path_or_obj,
				$action,
				$methods,
				$route_name,
			];
		}
	}

	public function done(): void
	{
		$this->done = true;
	}
}
