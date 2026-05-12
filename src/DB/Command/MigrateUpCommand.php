<?php

declare(strict_types=1);

namespace ON\DB\Command;

use Cycle\Migrations;
use ON\DB\DatabaseConfig;
use ON\DB\DatabaseManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateUpCommand extends Command
{
	public function __construct(
		protected DatabaseManager $databaseManager,
		protected DatabaseConfig $dbCfg
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->ignoreValidationErrors();

		$this
			->setName('db:migrate:up')
			->setAliases(['migrate'])
			->setDefinition([
			])
			->setDescription('Run the next pending migration. Alias: migrate')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$db = $this->databaseManager;
		$dbal = $db->getDatabase("cycle")->getConnection();

		$config = $this->dbCfg->get('migration');

		$migrator = new Migrations\Migrator($config, $dbal, new Migrations\FileRepository($config));

		// Init migration table
		$migrator->run();

		return Command::SUCCESS;
	}
}
