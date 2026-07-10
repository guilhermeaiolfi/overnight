<?php

declare(strict_types=1);

namespace ON\DataIntegration\Command;

use ON\DataIntegration\Definition\DefinitionCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DefinitionsClearCommand extends Command
{
	public function __construct(
		private readonly DefinitionCache $cache,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('definitions:clear')
			->setDescription('Clear cached ON\Data definitions.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$this->cache->clear();
		$output->writeln('ON\Data definitions cache cleared.');

		return Command::SUCCESS;
	}
}
