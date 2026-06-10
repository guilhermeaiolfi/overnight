<?php

declare(strict_types=1);

namespace ON\Cache;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CacheClearerDefinition
{
	/**
	 * @param callable(ContainerInterface, OutputInterface): void $clear
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $label,
		private readonly mixed $clear,
		public readonly int $priority = 0,
		public readonly bool $includedInAll = true,
		public readonly ?string $description = null,
	) {
	}

	public function clear(ContainerInterface $container, OutputInterface $output): void
	{
		($this->clear)($container, $output);
	}
}
