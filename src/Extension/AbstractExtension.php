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

	protected int $type = self::TYPE_MODULE;

	protected ?ExtensionLifecycle $__lifecycle = null;

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
		return $this->getLifecycle()->dispatchStateChange($this, $state, $data);
	}

	public function when(string $state, callable $callback): self
	{
		return $this->getLifecycle()->when($this, $state, $callback);
	}

	public function setLifecycle(ExtensionLifecycle $lifecycle): void
	{
		$this->__lifecycle = $lifecycle;
	}

	public function transitionTo(string $state): void
	{
		$this->__stateHistory[] = $this->__currentState;
		$this->__currentState = $state;
	}

	public function isInState(string $state): bool
	{
		return $this->__currentState === $state;
	}

	public function wasInState(string $state): bool
	{
		return in_array($state, $this->__stateHistory, true);
	}

	protected function getLifecycle(): ExtensionLifecycle
	{
		if (! isset($this->__lifecycle)) {
			$this->__lifecycle = new ExtensionLifecycle();
		}

		return $this->__lifecycle;
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
