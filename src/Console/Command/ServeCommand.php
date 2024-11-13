<?php

declare(strict_types=1);

namespace ON\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ServeCommand extends Command
{
	public function __construct(
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->ignoreValidationErrors();

		$this
			->setName('serve')
			->setDefinition([
				new InputOption('host', null, InputOption::VALUE_REQUIRED, 'Host', 'localhost'),
				new InputOption('port', null, InputOption::VALUE_REQUIRED, 'Port', 8000),
				new InputOption('publicDir', null, InputOption::VALUE_REQUIRED, 'Public Directoy (where index.php is at)', "public/"),
			])
			->setDescription('Start a PHP built-in development server');
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$host = $input->getOption("host");
		$port = $input->getOption("port");
		$publicDir = $input->getOption("publicDir");
		passthru("php -S {$host}:{$port} -t {$publicDir}");

		return Command::SUCCESS;
	}
}
