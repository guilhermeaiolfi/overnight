<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\RestApi\Support\AuthorizationGuard;
use Psr\EventDispatcher\EventDispatcherInterface;

final class RestEventManager
{
	/** @var list<object|callable(): ?object> */
	private array $afterEvents = [];

	public function __construct(
		private EventDispatcherInterface $eventDispatcher,
	) {}

	public function dispatch(object $event, bool $inheritNestedAuthorization = false): object
	{
		if ($inheritNestedAuthorization && $event instanceof AuthorizationAwareEventInterface) {
			$event->inheritNestedAuthorization();
			if ($event->getAuthState() === AuthState::Pending) {
				$event->allow();
			}
		}

		$this->eventDispatcher->dispatch($event);
		AuthorizationGuard::assert($event);

		return $event;
	}

	/**
	 * @param list<object> $events
	 */
	public function dispatchBeforeEvents(array $events): void
	{
		$inheritNestedAuthorization = false;
		foreach ($events as $event) {
			$path = method_exists($event, 'getPath') ? $event->getPath() : [];
			$this->dispatch($event, $inheritNestedAuthorization && $path !== []);

			if ($path === [] && $this->shouldInheritAuthToNested($event)) {
				$inheritNestedAuthorization = true;
			}
		}
	}

	/**
	 * @param object|callable(): ?object|list<object|callable(): ?object> $event
	 */
	public function scheduleAfterEvent(object|array|callable $event): object|array|callable
	{
		if (is_array($event)) {
			foreach ($event as $item) {
				$this->scheduleAfterEvent($item);
			}

			return $event;
		}

		$this->afterEvents[] = $event;

		return $event;
	}

	public function dispatchAfterEvents(): void
	{
		foreach ($this->afterEvents as $event) {
			if (is_callable($event)) {
				$event = $event();
			}
			if ($event === null) {
				continue;
			}

			$this->eventDispatcher->dispatch($event);
		}

		$this->afterEvents = [];
	}

	public function shouldInheritAuthToNested(object $event): bool
	{
		return $event instanceof AuthorizationAwareEventInterface
			&& $event->shouldInheritAuthToNested();
	}
}
