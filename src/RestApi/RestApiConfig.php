<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\Config\Config;

class RestApiConfig extends Config
{
	public string $endpointUri = '/items';
	public string $database = 'cycle';
	public string $cycleDatabase = 'default';
	public int $defaultLimit = 100;
	public int $maxLimit = 1000;
	public int $rateLimit = 100;
	public int $rateLimitWindow = 60;
	public array $addons = [];
	public array $dynamicVariables = [];

	/** @var array<string, string> */
	public array $validationMessages = [];

	public string $validationLang = 'en';

	/** @var list<array{name: string, methods: list<string>, path: string, action: class-string, options: array<string, mixed>}> */
	public array $actions = [];

	public function addAction(
		string $name,
		string|array $methods,
		string $path,
		string $actionClass,
		array $options = []
	): self {
		$methods = array_map(
			static fn (string $method): string => strtoupper($method),
			is_array($methods) ? array_values($methods) : [$methods]
		);

		$this->actions[] = [
			'name' => $name,
			'methods' => $methods,
			'path' => $path,
			'action' => $actionClass,
			'options' => $options,
		];

		return $this;
	}

	public function clearActions(): self
	{
		$this->actions = [];

		return $this;
	}

	public function hasActions(): bool
	{
		return $this->actions !== [];
	}
}
