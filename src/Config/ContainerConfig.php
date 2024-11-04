<?php

declare(strict_types=1);

namespace ON\Config;

class ContainerConfig extends Config
{
	public function addFactory(string $key, string $value = null): void
	{
		$this->set("definitions.factories." . $key, $value);
	}

	public function addFactories(array $keys): void
	{
		foreach ($keys as $key => $value) {
			$this->set("definitions.factories." . $key, $value);
		}
	}

	public function addAlias(string $key, string $value): void
	{
		$this->set("definitions.aliases." . $key, $value);
	}

	public function addAliases(array $keys): void
	{
		foreach ($keys as $key => $value) {
			$this->set("definitions.aliases." . $key, $value);
		}
	}
}
