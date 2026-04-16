<?php

declare(strict_types=1);

namespace ON\DB;

use On\Config\Config;
use ON\ORM\Definition\Registry;

class DatabaseConfig extends Config
{
	protected Registry $registry;

	public function addDatabase(
		string $name,
		?string $dsn = null,
		?array $attributes = [],
		?string $username = "",
		?string $password = "",
		?string $class = PdoDatabase::class,
		?string $wrapper_class = null,
		?array $options = [],
		bool $persistent = false,
		bool $debug = false
	) {
		$this->set("databases.{$name}", [
			"dsn" => $dsn,
			"attributes" => $attributes,
			"username" => $username,
			"password" => $password,
			"class" => $class,
			"wrapper_class" => $wrapper_class,
			"options" => $options,
			"persistent" => $persistent,
			"debug" => $debug,
		]);

		if (! $this->get('default')) {
			$this->set('default', $name);
		}

		return $this;
	}

	public function hasDefault(): bool
	{
		return $this->has('default');
	}

	public function setDefault(string $name)
	{
		$this->set('default', $name);

		return $this;
	}

	public function getDatabase(string $name): array
	{
		return $this->get("databases.{$name}");
	}

	public function getDefault()
	{
		if ($this->hasDefault()) {
			return null;
		}
		$name = $this->get('default');

		return $this->get("databases.{$name}");
	}

	public function getDefaultName()
	{
		return $this->get('default');
	}

	public function getRegistry(): Registry
	{
		if (! isset($this->registry)) {
			$this->registry = new Registry();
		}

		return $this->registry;
	}
}
