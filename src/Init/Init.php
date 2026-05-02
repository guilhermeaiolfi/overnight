<?php

declare(strict_types=1);

namespace ON\Init;

use BackedEnum;
use Throwable;

class Init
{
	/** @var array<string, array{owner: ?string, callback: callable}[]> */
	private array $listeners = [];

	/** @var array<string, array{owner: ?string, callback: callable}[]> */
	private array $doneListeners = [];

	/** @var array<string, string[]> [ListenerExtensionClass => [EventName]] */
	private array $subscriptionMap = [];

	private ?string $currentExtension = null;

	private InitContext $context;

	/** @var string[] */
	private array $eventStack = [];

	/** @var array<int, array{event: string, payload: object}> */
	private array $eventHistory = [];

	public function __construct()
	{
		$this->context = new InitContext($this);
	}

	public function setCurrentExtension(?string $extensionClass): void
	{
		$this->currentExtension = $extensionClass;
	}

	public function getCurrentExtension(): ?string
	{
		return $this->currentExtension;
	}

	public function on(object|string $event, ?callable $listener = null): EventBinding
	{
		$name = $this->normalizeEventName($event);
		$binding = new EventBinding($this, $name);

		if ($listener !== null) {
			$binding->listen($listener);
		}

		return $binding;
	}

	public function context(): InitContext
	{
		return $this->context;
	}

	public function emit(object|string $event, ?object $payload = null): object
	{
		if (is_object($event) && $payload === null) {
			$payload = $event;
			$name = get_class($event);
		} else {
			$name = $this->normalizeEventName($event);
			/** @var object $payload */
		}

		$listeners = $this->listeners[$name] ?? [];
		$doneListeners = $this->doneListeners[$name] ?? [];

		$this->eventHistory[] = [
			'event' => $name,
			'payload' => $payload
		];

		$this->eventStack[] = $name;

		try {
			foreach ($listeners as $item) {
				$this->invoke($item['callback'], $payload);
			}

			foreach ($doneListeners as $item) {
				$this->invoke($item['callback'], $payload);
			}
		} catch (Throwable $e) {
			throw new InitException($name, $this->eventStack, $e);
		} finally {
			array_pop($this->eventStack);
		}

		return $payload;
	}

	public function addListener(string $event, callable $listener): void
	{
		if ($this->currentExtension) {
			$this->subscriptionMap[$this->currentExtension][] = $event;
		}

		$this->listeners[$event][] = [
			'owner' => $this->currentExtension,
			'callback' => $listener
		];
	}

	public function addDoneListener(string $event, callable $listener): void
	{
		if ($this->currentExtension) {
			$this->subscriptionMap[$this->currentExtension][] = $event;
		}

		$this->doneListeners[$event][] = [
			'owner' => $this->currentExtension,
			'callback' => $listener
		];
	}

	/**
	 * Sorts listeners based on the provided extension order.
	 * Extensions later in the order will have their listeners run later.
	 */
	public function sortListeners(array $orderedClasses): void
	{
		$orderMap = array_flip($orderedClasses);
		$defaultOrder = count($orderedClasses); // For internal/unrecognized extensions

		$sortFn = function (array $a, array $b) use ($orderMap, $defaultOrder) {
			$orderA = $orderMap[$a['owner']] ?? $defaultOrder;
			$orderB = $orderMap[$b['owner']] ?? $defaultOrder;
			return $orderA <=> $orderB;
		};

		foreach ($this->listeners as $event => &$listeners) {
			usort($listeners, $sortFn);
		}

		foreach ($this->doneListeners as $event => &$listeners) {
			usort($listeners, $sortFn);
		}
	}

	/**
	 * @return array<string, string[]> [ExtensionClass => [EventName]]
	 */
	public function getSubscriptionMap(): array
	{
		return $this->subscriptionMap;
	}

	/** @return string[] */
	public function getEventStack(): array
	{
		return $this->eventStack;
	}

	/** @return array<int, array{event: string, payload: object}> */
	// this is different from the stack, stack is just the events that are currently being processed
	// and the history is all the events that have been processed
	public function getEventHistory(): array
	{
		return $this->eventHistory;
	}

	private function normalizeEventName(object|string $event): string
	{
		if ($event instanceof BackedEnum) {
			return (string) $event->value;
		}

		if (is_object($event)) {
			return get_class($event);
		}

		return $event;
	}

	private function invoke(callable $listener, object $payload): mixed
	{
		return $listener($payload, $this->context);
	}
}
