<?php

declare(strict_types=1);

namespace ON\DB;

use ON\Application;
use ON\CMS\Definition\Interface\TagsInterface;
use ON\CMS\Definition\Relation\HasManyRelation;
use ON\CMS\Definition\Relation\HasOneRelation;
use ON\Config\ConfigExtension;
use ON\Container\ContainerConfig;
use ON\DB\Command\MigrateCommand;
use ON\DB\Command\MigrateDownCommand;
use ON\DB\Command\MigrateUpCommand;
use ON\DB\Container\CycleDatabaseFactory;
use ON\DB\Cycle\CycleDatabase;
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
				$console->addCommand(MigrateUpCommand::class);
				$console->addCommand(MigrateDownCommand::class);
			});
		}

		$this->app->ext('config')->when('setup', function (ConfigExtension $configExt) {
			$containerConfig = $configExt->get(ContainerConfig::class);
			$containerConfig->addFactory(CycleDatabase::class, CycleDatabaseFactory::class);
		});
	}

	public function setup(): void
	{
		$registry = $this->app->config->get(DatabaseConfig::class)->getRegistry();

		$registry->collection("users")
		->field('id')
			->primaryKey(true)
			->typecast("int")
		->end()
		->field('email')
			->type('string')
		->end()
		->field('password')
			->type('string')
			->sensible(true)
		->end()
		->field("created_at")
			->type('datetime')
			->nullable(true)
		->end()
		->field("time")
			->type('time')
			->nullable(true)
		->end()
		->field("name")
			->column("name")
			->type('string')
			->interface(TagsInterface::class)
				->az(true)
			->end()
		->end()
		->relation("parts", HasManyRelation::class)
			->collection('parts')
			->nullable(true)
			->cascade(true)
			->innerKey('id')
			->outerKey('user_id')
		->end();

		$registry->collection("parts")
		->field('id')
			->primaryKey(true)
			->typecast("int")
		->end()
		->field('name')->type('string')->end()
		->field('user_id')->type('int')->end()
		->relation("user", HasOneRelation::class)
			->collection('users')
			->nullable(true)
			->cascade(true)
			->innerKey('user_id')
			->outerKey('id')
		->end();

		$this->setState('ready');
	}

	public function onContainerConfig(): void
	{

	}

	public function onConfigSetup(): void
	{

	}
}
