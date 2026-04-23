<?php

declare(strict_types=1);

namespace ON\Init;

use BackedEnum;
use Closure;
use ReflectionFunction;
use ReflectionMethod;
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

	private function normalizeEventName(string|BackedEnum $event): string
	{
		return $event instanceof BackedEnum ? (string) $event->value : $event;
	}

	private function invoke(callable $listener, object $payload): mixed
	{
		return $listener(...$this->argumentsFor($listener, $payload));
	}

	/** @return object[] */
	private function argumentsFor(callable $listener, object $payload): array
	{
		$parameters = $this->reflectionFor($listener)->getNumberOfParameters();

		if ($parameters === 0) {
			return [];
		}

		if ($parameters >= 2) {
			return [$payload, $this->context];
		}

		return [$payload];
	}

	private function reflectionFor(callable $listener): ReflectionFunction|ReflectionMethod
	{
		if (is_array($listener)) {
			return new ReflectionMethod($listener[0], $listener[1]);
		}

		if ($listener instanceof Closure) {
			return new ReflectionFunction($listener);
		}

		if (is_object($listener) && method_exists($listener, '__invoke')) {
			return new ReflectionMethod($listener, '__invoke');
		}

		return new ReflectionFunction($listener);
	}
}
