<?php

declare(strict_types=1);

namespace ON\Container;

use ON\Config\Config;

class ContainerConfig extends Config
{
	public function addFactory(string $key, string $value): void
	{
		$this->set("definitions.factories." . $key, $value);
	}

	public function addFactories(array $factories): void
	{
		foreach ($factories as $key => $value) {
			$this->set("definitions.factories." . $key, $value);
		}
	}

	public function addAlias(string $key, string $value): void
	{
		$this->set("definitions.aliases." . $key, $value);
	}

	public function addAliases(array $aliases): void
	{
		foreach ($aliases as $key => $value) {
			$this->set("definitions.aliases." . $key, $value);
		}
	}
}
