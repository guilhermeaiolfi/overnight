<?php

declare(strict_types=1);

namespace ON\Cache;

use ON\Cache\Exception\DuplicateCacheClearerException;

final class CacheClearerRegistry
{
	/** @var array<string, CacheClearerDefinition> */
	private array $definitions = [];

	public function add(CacheClearerDefinition $definition): void
	{
		if ($this->has($definition->name)) {
			throw new DuplicateCacheClearerException(sprintf(
				'Cache clearer "%s" is already registered.',
				$definition->name
			));
		}

		$this->definitions[$definition->name] = $definition;
	}

	public function has(string $name): bool
	{
		return isset($this->definitions[$name]);
	}

	public function get(string $name): CacheClearerDefinition
	{
		if (! $this->has($name)) {
			throw new \InvalidArgumentException(sprintf(
				'Cache clearer "%s" is not registered.',
				$name
			));
		}

		return $this->definitions[$name];
	}

	/**
	 * @return array<string, CacheClearerDefinition>
	 */
	public function all(): array
	{
		$definitions = $this->definitions;
		uasort($definitions, function (CacheClearerDefinition $a, CacheClearerDefinition $b): int {
			return $b->priority <=> $a->priority
				?: strcasecmp($a->label, $b->label);
		});

		return $definitions;
	}
}
