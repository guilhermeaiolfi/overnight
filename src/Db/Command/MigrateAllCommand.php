<?php

declare(strict_types=1);

namespace ON\DB\Command;

use Cycle\Migrations;
use Cycle\Migrations\Exception\MigrationException;
use ON\DB\DatabaseConfig;
use ON\DB\DatabaseManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateAllCommand extends Command
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
			->setName('db:migrate:all')
			->setDescription('Run all pending migrations.')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$db = $this->databaseManager;
		$dbal = $db->getDatabase('cycle')->getConnection();
		$config = $this->dbCfg->get('migration');
		$migrator = new Migrations\Migrator($config, $dbal, new Migrations\FileRepository($config));

		if (! $migrator->isConfigured()) {
			$io->warning('Migration tracking is not initialized yet. Running "db:migrate:init" first.');
			$migrator->configure();
		}

		$ran = 0;

		while (true) {
			try {
				$migration = $migrator->run();
			} catch (MigrationException $exception) {
				$io->error($exception->getMessage());

				return Command::FAILURE;
			}

			if ($migration === null) {
				break;
			}

			$state = $migration->getState();
			$io->text(sprintf(
				'Applied %s (%s)',
				$state->getName(),
				$state->getTimeCreated()->format('Y-m-d H:i:s')
			));
			$ran++;
		}

		if ($ran === 0) {
			$io->success('Database schema is already up to date.');

			return Command::SUCCESS;
		}

		$io->success(sprintf('Applied %d pending migration(s).', $ran));

		return Command::SUCCESS;
	}
}
