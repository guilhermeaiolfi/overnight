<?php

declare(strict_types=1);

namespace ON\Console;

use Exception;
use ON\Application;
use ON\Console\Command\OvernightCommand;
use ON\Console\Command\ServeCommand;
use ON\Console\Init\Event\ConsoleReadyEvent;
use ON\Container\Executor\ExecutorInterface;
use ON\Container\Init\Event\ContainerReadyEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
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
		ServeCommand::class,
	];

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
		$this->consoleApp = new ConsoleApplication();
	}

	public function getConsoleApp(): ConsoleApplication
	{
		return $this->consoleApp ??= new ConsoleApplication();
	}

	public function register(Init $init): void
	{
		if ($this->app->isCli()) {
			$this->app->setRunner([$this, 'run']);
		}
	}

	public function start(InitContext $context): void
	{
		$this->getConsoleApp();

		$context->emit(new ConsoleReadyEvent($this));
	}

	#[On(optional: true)]
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
			$this->getConsoleApp()->add($command);
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
			throw new Exception('You need to pass at least one param when adding commands.');
		}
	}

	public function flush(): void
	{
		if (! isset($this->container)) {
			throw new Exception('Console commands requiring services need the container extension to be ready.');
		}

		$consoleApp = $this->getConsoleApp();
		$executor = $this->container->get(ExecutorInterface::class);
		foreach ($this->q as $index => $command) {
			if ($command instanceof OvernightCommand) {
				$command->setExecutor($executor);
				$consoleApp->add($command);
			} elseif ($command instanceof Command) {
				$consoleApp->add($command);
			} elseif (is_string($command)) {
				$consoleApp->add($this->container->get($command));
			} else {
				throw new Exception('Unrecognized command type: ' . get_debug_type($command));
			}

			unset($this->q[$index]);
		}
	}

	public function run()
	{
		$this->flush();

		return $this->getConsoleApp()->run();
	}
}
