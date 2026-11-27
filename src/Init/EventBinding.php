<?php

declare(strict_types=1);

namespace ON\Init;

final class EventBinding
{
	private bool $optional;

	public function __construct(
		private Init $init,
		private string $event,
		array $options = [],
		?bool $optional = null,
		private InitStage $stage = InitStage::On,
	) {
		$this->optional = $optional ?? (bool) ($options['optional'] ?? false);
	}

	public function before(callable $listener, ?bool $optional = null): self
	{
		return $this->listenAt(InitStage::Before, $listener, $optional);
	}

	public function listen(callable $listener, ?bool $optional = null): self
	{
		return $this->listenAt($this->stage, $listener, $optional);
	}

	public function after(callable $listener, ?bool $optional = null): self
	{
		return $this->listenAt(InitStage::After, $listener, $optional);
	}

	public function done(callable $listener, ?bool $optional = null): self
	{
		return $this->after($listener, $optional);
	}

	private function listenAt(InitStage $stage, callable $listener, ?bool $optional): self
	{
		$this->init->addStageListener($this->event, $listener, $stage, $optional ?? $this->optional);

		return $this;
	}
}
