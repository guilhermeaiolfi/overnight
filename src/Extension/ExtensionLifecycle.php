<?php

declare(strict_types=1);

namespace ON\Extension;

use RuntimeException;

class ExtensionLifecycle
{
	private array $listeners = [];

	private array $deferredEvents = [];

	public function when(ExtensionInterface $extension, string $state, mixed $callback): ExtensionInterface
	{
		$id = spl_object_id($extension);
		$this->listeners[$id][$state] ??= [];
		$this->listeners[$id][$state][] = $callback;

		if ($extension->isInState($state) || $extension->wasInState($state)) {
			$this->defer($callback, $extension);
		}

		return $extension;
	}

	public function dispatchStateChange(ExtensionInterface $extension, string $state, mixed $data = null): ExtensionInterface
	{
		$extension->transitionTo($state);

		$id = spl_object_id($extension);
		foreach ($this->listeners[$id][$state] ?? [] as $callback) {
			$this->defer($callback, $extension, $data);
		}

		return $extension;
	}

	public function flushDeferredEvents(): int
	{
		$count = 0;
		$events = $this->deferredEvents;
		$this->deferredEvents = [];

		foreach ($events as [$callback, $extension, $data]) {
			$count++;
			$this->invoke($callback, $extension, $data);
		}

		return $count;
	}

	public function settle(iterable $extensions, int $maxPasses = 100): void
	{
		$notReady = [];
		$emptyPassesByExtension = [];

		foreach ($extensions as $extension) {
			if (! $extension->isReady()) {
				$notReady[spl_object_id($extension)] = $extension;
			}
		}

		while (! empty($notReady)) {
			$flushed = $this->flushDeferredEvents();

			foreach ($notReady as $id => $extension) {
				if ($extension->isReady()) {
					unset($notReady[$id], $emptyPassesByExtension[$id]);
				}
			}

			if (empty($notReady) || $flushed > 0) {
				continue;
			}

			foreach ($notReady as $id => $extension) {
				$emptyPassesByExtension[$id] = ($emptyPassesByExtension[$id] ?? 0) + 1;
				if ($emptyPassesByExtension[$id] > $maxPasses) {
					throw new RuntimeException(sprintf(
						"Extension lifecycle did not settle for %s after %d empty deferred-event passes. Namespace: %s. State: %s. History: %s",
						$extension::class,
						$maxPasses,
						$extension->getNamespace(),
						$extension->getState(),
						implode(' -> ', $extension->getStateHistory())
					));
				}
			}
		}
	}

	private function defer(mixed $callback, ExtensionInterface $extension, mixed $data = null): void
	{
		$this->deferredEvents[] = [$callback, $extension, $data];
	}

	private function invoke(mixed $callback, ExtensionInterface $extension, mixed $data = null): mixed
	{
		if (is_array($callback) && is_object($callback[0] ?? null) && is_string($callback[1] ?? null)) {
			$object = $callback[0];
			$method = $callback[1];

			$invoke = \Closure::bind(function () use ($method, $extension, $data) {
				return $this->$method($extension, $data);
			}, $object, $object::class);

			return $invoke();
		}

		return $callback($extension, $data);
	}

}
