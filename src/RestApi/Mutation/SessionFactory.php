<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\DataRuntime;
use ON\Data\ORM\Session;

/**
 * Builds ON\Data Session instances from the shared DataRuntime command executor.
 */
final class SessionFactory
{
	public function __construct(
		private readonly DataRuntime $runtime,
	) {
	}

	public function create(): Session
	{
		return new Session($this->runtime->getCommandExecutor());
	}
}
