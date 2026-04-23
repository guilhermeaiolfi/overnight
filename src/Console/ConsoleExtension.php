<?php

declare(strict_types=1);

namespace ON\Console;

use Exception;
use ON\Application;
use ON\Console\Command\ClearCacheCommand;
use ON\Console\Command\OvernightCommand;
use ON\Console\Command\RoutesCommand;
use ON\Console\Command\ServeCommand;
use ON\Container\Init\ContainerInitEvents;
use ON\Container\Init\Event\ContainerReadyEvent;
use ON\Container\Executor\ExecutorInterface;
use ON\Extension\AbstractExtension;
use ON\Console\Init\ConsoleInitEvents;
use ON\Console\Init\Event\ConsoleReadyEvent;
use ON\Init\Init;
use ON\Init\InitContext;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;

class ConsoleExtension extends AbstractExtension
{
	public const ID = 'console';

	protected ?ConsoleApplication $consoleApp = null;
	protected ?ContainerInterface $container = null;

	protected array $q = [
		ClearCacheCommand::class,
		RoutesCommand::class,
		ServeCommand::class,
	];
	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function register(Init $init): void
	{
		$init->on(ContainerInitEvents::READY, [$this, 'onContainerReady']);
	}

	public function start(InitContext $context): void
	{
		
		if ($this->app->isCli()) {
			$this->app->registerMethod("run", [$this, "run"]);
		}


		$this->consoleApp = new ConsoleApplication();

		$context->emit(ConsoleInitEvents::READY, new ConsoleReadyEvent($this));
	}

	public function onContainerReady(ContainerReadyEvent $event): void
	{
		$this->container = $event->container;
	}

	protected function addOvernightCommand(string $name, string $action, ?string $description = null): void
	{
		$command = new OvernightCommand();

		$command
		->setName($name)
		->setAction($action);

		if (isset($description)) {
			$command->setDescription($description);
		}

		if (isset($this->container)) {
			$executor = $this->container->get(ExecutorInterface::class);
			$command->setExecutor($executor);
			$this->consoleApp->add($command);
		} else {
			$this->q[] = $command;
		}
	}

	public function addCommand(...$args): void
	{
		if (count($args) > 1) {
			$name = $args[0];
			$action = $args[1];
			$description = null;
			if (isset($args[2])) {
				$description = $args[2];
			}
			$this->addOvernightCommand($name, $action, $description);
		} elseif (count($args) == 1) {
			$this->q[] = $args[0];
		} else {
			throw new Exception("You need to pass at least one param when adding commands.");
		}
	}

	public function flush(): void
	{
		if (! isset($this->container)) {
			throw new Exception('Console commands requiring services need the container extension to be ready.');
		}

		$executor = $this->container->get(ExecutorInterface::class);
		foreach ($this->q as $command) {
			if ($command instanceof OvernightCommand) {
				$command->setExecutor($executor);
				$this->consoleApp->add($command);
			} elseif ($command instanceof Command) {
				$this->consoleApp->add($command);
			} elseif (is_string($command)) {
				$this->consoleApp->add($this->container->get($command));
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
