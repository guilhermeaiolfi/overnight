<?php

declare(strict_types=1);

namespace ON\Extension;

abstract class AbstractExtension implements ExtensionInterface
{
	public const TYPE_MODULE = 1;
	public const TYPE_EXTENSION = 2;
	public const TYPE_AGGREGATION = 3;

	public const VERSION = "UNVERSIONED";

	public const NAMESPACE = "core.extensions.abstract";

	protected string $__currentState = "start";

	protected array $__stateHistory = [];

	protected array $__states = [];

	protected array $__when = [];

	protected int $type = self::TYPE_MODULE;

	public function getType(): int
	{
		return $this->type;
	}

	public function isReady(): bool
	{
		return $this->__currentState == "ready";
	}

	public function getNamespace(): string
	{
		return static::NAMESPACE;
	}

	public function getState(): string
	{
		return $this->__currentState;
	}

	public function getStateHistory(): array
	{
		return $this->__stateHistory;
	}

	public function dispatchStateChange(string $state, mixed $data = null): self
	{
		$this->__stateHistory[] = $this->__currentState;
		$this->__currentState = $state;
		if (isset($this->__when[$state])) {
			foreach ($this->__when[$state] as $callback) {
				$callback($this, $data);
			}
		}

		return $this;
	}

	public function when(string $state, callable $callback): self
	{
		$this->__when[$state] = isset($this->__when[$state]) ? $this->__when[$state] : [];
		$this->__when[$state][] = $callback;

		if ($this->__currentState == $state || in_array($state, $this->__stateHistory)) {
			$callback($this, null);
		}

		return $this;
	}

	public function setup(): void
	{
	}

	public function requires(): array
	{
		return [];
	}

	public function boot(): void
	{

	}

	public function getHooks(): array
	{
		return [];
	}
}
