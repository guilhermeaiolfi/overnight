<?php

declare(strict_types=1);

namespace ON\DB\Command;

use Cycle\Migrations;
use Cycle\Migrations\State;
use ON\DB\DatabaseConfig;
use ON\DB\DatabaseManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateStatusCommand extends Command
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
			->setName('db:migrate:status')
			->setDescription('Show the current migration position and any pending migrations.')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$db = $this->databaseManager;
		$dbal = $db->getDatabase("cycle")->getConnection();
		$config = $this->dbCfg->get('migration');
		$migrator = new Migrations\Migrator($config, $dbal, new Migrations\FileRepository($config));

		if (! $migrator->isConfigured()) {
			$io->warning('Migration tracking is not initialized yet. Run "php console db:migrate:init" first.');

			return Command::SUCCESS;
		}

		$migrations = $migrator->getMigrations();
		$executed = [];
		$pending = [];

		foreach ($migrations as $migration) {
			$state = $migration->getState();
			$row = [
				$state->getName(),
				$state->getTimeCreated()->format('Y-m-d H:i:s'),
				$state->getTimeExecuted()?->format('Y-m-d H:i:s') ?? '-',
			];

			if ($state->getStatus() === State::STATUS_EXECUTED) {
				$executed[] = $row;
				continue;
			}

			$pending[] = $row;
		}

		$lastExecuted = end($executed);
		$nextPending = reset($pending);

		$io->definitionList(
			['Configured' => 'yes'],
			['Executed migrations' => (string) count($executed)],
			['Pending migrations' => (string) count($pending)],
			['Current version' => $lastExecuted[0] ?? 'No migrations executed yet'],
			['Next migration' => $nextPending[0] ?? 'Database is up to date']
		);

		if ($pending === []) {
			$io->success('Database schema is up to date.');

			return Command::SUCCESS;
		}

		$io->section('Pending migrations');
		$table = new Table($output);
		$table->setHeaders(['Migration', 'Created At', 'Executed At']);
		$table->setRows($pending);
		$table->render();

		return Command::SUCCESS;
	}
}
