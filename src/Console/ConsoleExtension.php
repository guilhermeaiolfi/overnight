<?php

declare(strict_types=1);

namespace ON\Console;

use ON\Application;
use ON\Console\Command\ClearCacheCommand;
use ON\Console\Command\OvernightCommand;
use ON\Console\Command\RoutesCommand;
use ON\Container\Executor\ExecutorInterface;
use ON\Extension\AbstractExtension;
use Symfony\Component\Console\Application as ConsoleApplication;

class ConsoleExtension extends AbstractExtension
{
	protected array $pendingTasks = [ 'init' ];

	protected ?ConsoleApplication $consoleApp = null;

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

	public function requires(): array
	{
		return [];
	}

	public function setup($counter): bool
	{
		if ($this->app->isCli()) {
			$this->app->registerMethod("run", [$this, "run"]);
		}
		$this->app->console = $this;
		$this->consoleApp = new ConsoleApplication();

		if ($this->removePendingTask('init')) {

		}


		if ($this->hasPendingTasks()) {
			return false;
		}

		return true;
	}

	public function run()
	{
		$this->consoleApp->add($this->app->container->get(ClearCacheCommand::class));
		$this->consoleApp->add($this->app->container->get(RoutesCommand::class));

		$command = new OvernightCommand();

		$executor = $this->app->container->get(ExecutorInterface::class);
		$command
			->setName("on:test")
			->setAction("App\\Page\\FooPage::command")
			->setDescription("To test running an overnight command")
			->setExecutor($executor);
		$this->consoleApp->add($command);
		$this->consoleApp->run();
	}
}
