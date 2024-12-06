<?php

declare(strict_types=1);

namespace ON\DB\Command;

use Cycle\Migrations;
use ON\Application;
use ON\DB\Manager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends Command
{
	public function __construct(
		protected Application $app
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->ignoreValidationErrors();

		$this
			->setName('db:migrate')
			->setDefinition([
			])
			->setDescription('Migrate database')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$db = $this->app->container->get(Manager::class);
		$dbal = $db->getDatabase("cycle")->getConnection();

		$config = new Migrations\Config\MigrationConfig([
			'directory' => 'var/migrations/', // where to store migrations
			'table' => 'on_migrations',                // database table to store migration status
			'safe' => true,                         // When set to true no confirmation will be requested on migration run.
		]);

		$migrator = new Migrations\Migrator($config, $dbal, new Migrations\FileRepository($config));

		// Init migration table
		$migrator->configure();

		return Command::SUCCESS;
	}
}
