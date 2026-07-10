<?php

declare(strict_types=1);

namespace ON\DataIntegration\Command;

use ON\DataIntegration\Definition\DefinitionCache;
use ON\DataIntegration\Definition\DefinitionRegistryProvider;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DefinitionsWarmupCommand extends Command
{
	public function __construct(
		private readonly DefinitionCache $cache,
		private readonly DefinitionRegistryProvider $provider,
		private readonly ContainerInterface $container,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('definitions:warmup')
			->setDescription('Build and cache ON\Data definitions.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$this->cache->clear();
		$this->provider->__invoke($this->container);
		$output->writeln('ON\Data definitions cache warmed.');

		return Command::SUCCESS;
	}
}
