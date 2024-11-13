<?php

declare(strict_types=1);

namespace ON\Logging\Command;

use ON\Application;
use ON\Console\TailReader;
use ON\Logging\LoggingConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorLogsCommand extends Command
{
	public function __construct(
		protected LoggerInterface $logger,
		protected Application $app
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->ignoreValidationErrors();

		$this
			->setName('monitor:logs')
			->setDefinition([

			])
			->setDescription('Monitor Logs');
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$logCfg = $this->app->config->get(LoggingConfig::class);
		$logCfg->get("path");
		if ($logCfg->get("type")) {

		}
		$handlers = $this->logger->getHandlers();
		if (empty($handlers)) {
			$output->writeln("No file found");

			return Command::SUCCESS;
		}
		$path = $handlers[0]->getUrl();

		if (! file_exists($path)) {
			$output->writeln("Resource/file does NOT exist");

			return Command::SUCCESS;
		}

		$output->writeln("Showing log file: {$path}");
		(new TailReader())->tail(
			path: $path,
			format: fn (string $text) => $text
		);

		return Command::SUCCESS;
	}
}
