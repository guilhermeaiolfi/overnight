<?php

declare(strict_types=1);

namespace ON\Console\Command;

use ON\Container\Executor\ExecutorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OvernightCommand extends Command
{
	protected ?string $action = null;
	protected ?ExecutorInterface $executor = null;

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$container = $this->executor->getContainer();
		[$class, $method] = explode("::", $this->action);
		$instance = $container->get($class);

		return $this->executor->execute([$instance, $method], [
			InputInterface::class => $input,
			OutputInterface::class => $output,
		]);

		return 0;
	}

	public function setAction(string $action): self
	{
		$this->action = $action;

		return $this;
	}

	public function getAction(): string
	{
		return $this->action;
	}

	public function setExecutor(ExecutorInterface $executor): self
	{
		$this->executor = $executor;

		return $this;
	}
}
