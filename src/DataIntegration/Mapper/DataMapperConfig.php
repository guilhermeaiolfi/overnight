<?php

declare(strict_types=1);

namespace ON\DataIntegration\Mapper;

use ON\Config\Config;

final class DataMapperConfig extends Config
{
	/** @var list<class-string> */
	public array $components = [];

	/** @var list<class-string> */
	public array $prependedComponents = [];

	/**
	 * @param class-string $component
	 */
	public function register(string $component): self
	{
		$this->components[] = $component;

		return $this;
	}

	/**
	 * @param class-string $component
	 */
	public function prepend(string $component): self
	{
		$this->prependedComponents[] = $component;

		return $this;
	}
}
