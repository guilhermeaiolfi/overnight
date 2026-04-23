<?php

declare(strict_types=1);

namespace ON\Init;

final class EventBinding
{
	public function __construct(
		private Init $init,
		private string $event
	) {
	}

	public function listen(callable $listener): self
	{
		$this->init->addListener($this->event, $listener);

		return $this;
	}

	public function done(callable $listener): self
	{
		$this->init->addDoneListener($this->event, $listener);

		return $this;
	}
}
