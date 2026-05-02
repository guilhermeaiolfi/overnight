<?php

declare(strict_types=1);

namespace ON\Init;

use BackedEnum;

final class InitContext
{
	public function __construct(
		private Init $init
	) {
	}

	public function emit(object|string $event, ?object $payload = null): object
	{
		return $this->init->emit($event, $payload);
	}

	/** @return string[] */
	public function getEventStack(): array
	{
		return $this->init->getEventStack();
	}

	/** @return array<int, array{event: string, payload: object}> */
	public function getEventHistory(): array
	{
		return $this->init->getEventHistory();
	}
}
