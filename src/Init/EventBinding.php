<?php

declare(strict_types=1);

namespace ON\Init;

final class EventBinding
{
	public function __construct(
		private Init $init,
		private string $event,
		private array $options = []
	) {
	}

	public function listen(callable $listener): self
	{
		$this->init->addListener($this->event, $listener, $this->options);

		return $this;
	}

	public function done(callable $listener): self
	{
		$this->init->addDoneListener($this->event, $listener, $this->options);

		return $this;
	}
}
