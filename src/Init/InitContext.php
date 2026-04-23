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

	public function emit(string|BackedEnum $event, object $payload): object
	{
		return $this->init->emit($event, $payload);
	}

	/** @return string[] */
	public function getEventStack(): array
	{
		return $this->init->getEventStack();
	}
}
