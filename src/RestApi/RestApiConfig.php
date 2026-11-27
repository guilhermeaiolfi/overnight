<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\Config\Config;
use ON\RestApi\Action\Directus\BatchDeleteAction;
use ON\RestApi\Action\Directus\BatchUpdateAction;
use ON\RestApi\Action\Directus\CreateAction;
use ON\RestApi\Action\Directus\DeleteAction;
use ON\RestApi\Action\Directus\FilesAction;
use ON\RestApi\Action\Directus\GetAction;
use ON\RestApi\Action\Directus\ListAction;
use ON\RestApi\Action\Directus\UpdateAction;

class RestApiConfig extends Config
{
	public string $endpointUri = '/items';
	public string $database = 'cycle';
	public string $cycleDatabase = 'default';
	public int $defaultLimit = 100;
	public int $maxLimit = 1000;
	public int $maxRelationDepth = 5;
	public int $maxFilterDepth = 8;
	public int $rateLimit = 100;
	public int $rateLimitWindow = 60;
	public string $filesCollection = 'directus_files';
	public int $maxBatchSize = 1000;
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

	public function registerDefaultActions(): void
	{
		if ($this->hasActions()) {
			return;
		}

		$this
			->addAction('directus.list', 'GET', '{collection}', ListAction::class)
			->addAction('directus.get', 'GET', '{collection}/{id}', GetAction::class)
			->addAction('directus.files', 'POST', 'files', FilesAction::class)
			->addAction('directus.create', 'POST', '{collection}', CreateAction::class)
			->addAction('directus.update', 'PATCH', '{collection}/{id}', UpdateAction::class)
			->addAction('directus.batch-update', 'PATCH', '{collection}', BatchUpdateAction::class)
			->addAction('directus.delete', 'DELETE', '{collection}/{id}', DeleteAction::class)
			->addAction('directus.batch-delete', 'DELETE', '{collection}', BatchDeleteAction::class);
	}
}
