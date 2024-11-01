<?php

declare(strict_types=1);

namespace ON\Console;

use Exception;
use ON\Application;
use ON\Console\Command\ClearCacheCommand;
use ON\Console\Command\OvernightCommand;
use ON\Console\Command\RoutesCommand;
use ON\Container\Executor\ExecutorInterface;
use ON\Extension\AbstractExtension;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;

class ConsoleExtension extends AbstractExtension
{
	protected array $pendingTasks = [ 'init' ];

	protected ?ConsoleApplication $consoleApp = null;

	protected array $q = [
		ClearCacheCommand::class,
		RoutesCommand::class,
	];

	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app, $options);

		$app->console = $extension;

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


		if ($this->removePendingTask('init')) {
			$this->consoleApp = new ConsoleApplication();
		}


		if ($this->hasPendingTasks()) {
			return false;
		}

		return true;
	}

	public function addCommand(string $name, string $action, ?string $description = null): void
	{
		$command = new OvernightCommand();


		$command
		->setName($name)
		->setAction($action);

		if (isset($description)) {
			$command->setDescription($description);
		}

		if ($this->app->isExtensionReady('container')) {
			$executor = $this->app->container->get(ExecutorInterface::class);
			$command->setExecutor($executor);
			$this->consoleApp->add($command);
		} else {
			$this->q[] = $command;
		}
	}

	public function flush(): void
	{
		$executor = $this->app->container->get(ExecutorInterface::class);
		foreach ($this->q as $command) {
			if ($command instanceof OvernightCommand) {
				$command->setExecutor($executor);
				$this->consoleApp->add($command);
			} elseif ($command instanceof Command) {
				$this->consoleApp->add($command);
			} elseif (is_string($command)) {
				$this->consoleApp->add($this->app->container->get($command));
			} else {
				throw new Exception("Unrecognized command type: {$command}");
			}
		}
	}

	public function run()
	{
		$this->flush();

		$this->consoleApp->run();
	}
}
