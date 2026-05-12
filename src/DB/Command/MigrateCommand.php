<?php

declare(strict_types=1);

namespace ON\DB\Command;

use Cycle\Migrations;
use ON\DB\DatabaseConfig;
use ON\DB\DatabaseManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends Command
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
			->setName('db:migrate:init')
			->setDefinition([
			])
			->setDescription('Initialize or repair the migration tracking table.')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$db = $this->databaseManager;
		$dbal = $db->getDatabase("cycle")->getConnection();

		$config = $this->dbCfg->get('migration');

		$migrator = new Migrations\Migrator($config, $dbal, new Migrations\FileRepository($config));

		// Init migration table
		$migrator->configure();

		return Command::SUCCESS;
	}
}
