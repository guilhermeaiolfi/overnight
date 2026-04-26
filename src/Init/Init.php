<?php

declare(strict_types=1);

namespace ON\Init;

use BackedEnum;
use Throwable;

class Init
{
	/** @var array<string, callable[]> */
	private array $listeners = [];

	/** @var array<string, callable[]> */
	private array $doneListeners = [];

	private InitContext $context;

	/** @var string[] */
	private array $eventStack = [];

	/** @var array<int, array{event: string, payload: object}> */
	private array $eventHistory = [];

	public function __construct()
	{
		$this->context = new InitContext($this);
	}

	public function on(string|BackedEnum $event, ?callable $listener = null): EventBinding
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

	public function emit(string|BackedEnum $event, object $payload): object
	{
		$name = $this->normalizeEventName($event);
		$listeners = $this->listeners[$name] ?? [];
		$doneListeners = $this->doneListeners[$name] ?? [];

		$this->eventHistory[] = [
			'event' => $name,
			'payload' => $payload
		];

		$this->eventStack[] = $name;

		try {
			foreach ($listeners as $listener) {
				$this->invoke($listener, $payload);
			}

			foreach ($doneListeners as $listener) {
				$this->invoke($listener, $payload);
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
		$this->listeners[$event][] = $listener;
	}

	public function addDoneListener(string $event, callable $listener): void
	{
		$this->doneListeners[$event][] = $listener;
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

	private function normalizeEventName(string|BackedEnum $event): string
	{
		return $event instanceof BackedEnum ? (string) $event->value : $event;
	}

	private function invoke(callable $listener, object $payload): mixed
	{
		return $listener($payload, $this->context);
	}
}
