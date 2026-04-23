<?php

declare(strict_types=1);

namespace ON\Init;

use RuntimeException;
use Throwable;

final class InitException extends RuntimeException
{
	/**
	 * @param string[] $eventStack
	 */
	public function __construct(
		private string $event,
		private array $eventStack,
		Throwable $previous
	) {
		parent::__construct(
			sprintf(
				'Init listener failed during %s. Event stack: %s',
				$event,
				implode(' > ', $eventStack)
			),
			0,
			$previous
		);
	}

	public function getEvent(): string
	{
		return $this->event;
	}

	/** @return string[] */
	public function getEventStack(): array
	{
		return $this->eventStack;
	}
}
