<?php

declare(strict_types=1);

namespace Tests\ON\RestApi\Support;

use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\TransactionalCommandExecutorInterface;
use ON\Data\ORM\Persistence\UpdateCommand;

/**
 * Records persistence commands while delegating to a real executor (unlike overnight-data's stub recorder).
 */
final class DelegatingRecordingCommandExecutor implements CommandExecutorInterface, TransactionalCommandExecutorInterface
{
	/** @var list<CommandInterface> */
	private array $commands = [];

	public function __construct(
		private readonly CommandExecutorInterface $inner,
	) {
	}

	public function execute(CommandInterface $command): CommandResult
	{
		$this->commands[] = $command;

		return $this->inner->execute($command);
	}

	public function transaction(callable $callback): mixed
	{
		if (! $this->inner instanceof TransactionalCommandExecutorInterface) {
			return $callback();
		}

		return $this->inner->transaction($callback);
	}

	/**
	 * @return list<CommandInterface>
	 */
	public function getCommands(): array
	{
		return $this->commands;
	}

	/**
	 * @return list<UpdateCommand>
	 */
	public function getUpdateCommandsFor(string $collectionName): array
	{
		$matched = [];
		foreach ($this->commands as $command) {
			if ($command instanceof UpdateCommand && $command->getCollection()->getName() === $collectionName) {
				$matched[] = $command;
			}
		}

		return $matched;
	}
}
