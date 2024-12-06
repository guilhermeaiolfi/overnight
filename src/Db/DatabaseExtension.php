<?php

declare(strict_types=1);

namespace ON\DB;

use ON\Application;
use ON\DB\Command\MigrateCommand;
use ON\Extension\AbstractExtension;

class DatabaseExtension extends AbstractExtension
{
	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app, $options);

		return $extension;
	}

	public function __construct(
		protected Application $app,
		protected array $options
	) {
	}

	public function boot(): void
	{
		if ($this->app->isCli()) {
			$this->app->ext('console')->when('ready', function ($console) {
				$console->addCommand(MigrateCommand::class);
			});
		}
	}

	public function setup(): void
	{
		$this->setState('ready');
	}

	public function onContainerConfig(): void
	{

	}

	public function onConfigSetup(): void
	{

	}
}
