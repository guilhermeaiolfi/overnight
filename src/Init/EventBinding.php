<?php

declare(strict_types=1);

namespace ON\Init;

final class EventBinding
{
	public function __construct(
		private Init $init,
		private string $event,
		private array $options = [],
		private bool $optional = false,
	) {
	}

	public function before(callable $listener, ?bool $optional = null): self
	{
		$this->init->addBeforeListener($this->event, $listener, $this->options, $optional ?? $this->optional);

		return $this;
	}

	public function listen(callable $listener, ?bool $optional = null): self
	{
		$this->init->addListener($this->event, $listener, $this->options, $optional ?? $this->optional);

		return $this;
	}

	public function after(callable $listener, ?bool $optional = null): self
	{
		$this->init->addAfterListener($this->event, $listener, $this->options, $optional ?? $this->optional);

		return $this;
	}

	public function done(callable $listener, ?bool $optional = null): self
	{
		return $this->after($listener, $optional);
	}
}
