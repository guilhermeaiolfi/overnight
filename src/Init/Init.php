<?php

declare(strict_types=1);

namespace ON\Init;

use BackedEnum;
use ON\Extension\ExtensionInterface;
use ON\Extension\ExtensionProfiler;
use Throwable;

class Init
{
	/** @var array<string, list<array{owner: ?string, callback: callable, optional: bool}>> */
	private array $beforeListeners = [];

	/** @var array<string, list<array{owner: ?string, callback: callable, optional: bool}>> */
	private array $listeners = [];

	/** @var array<string, list<array{owner: ?string, callback: callable, optional: bool}>> */
	private array $afterListeners = [];

	/** @var array<string, list<array{event: string, optional: bool}>> */
	private array $subscriptions = [];

	private ?string $currentExtension = null;

	private InitContext $context;

	/** @var string[] */
	private array $eventStack = [];

	/** @var array<int, array{event: string, payload: object}> */
	private array $eventHistory = [];

	public function __construct(
		private ?ExtensionProfiler $profiler = null
	) {
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

	public function before(object|string $event, ?callable $listener = null, array $options = [], bool $optional = false): EventBinding
	{
		$name = $this->normalizeEventName($event);
		$isOptional = $optional || (bool) ($options['optional'] ?? false);
		$binding = new EventBinding($this, $name, $options, $isOptional);

		if ($listener !== null) {
			$this->addBeforeListener($name, $listener, $options, $isOptional);
		}

		return $binding;
	}

	public function on(object|string $event, ?callable $listener = null, array $options = [], bool $optional = false): EventBinding
	{
		$name = $this->normalizeEventName($event);
		$isOptional = $optional || (bool) ($options['optional'] ?? false);
		$binding = new EventBinding($this, $name, $options, $isOptional);

		if ($listener !== null) {
			$this->addListener($name, $listener, $options, $isOptional);
		}

		return $binding;
	}

	public function after(object|string $event, ?callable $listener = null, array $options = [], bool $optional = false): EventBinding
	{
		$name = $this->normalizeEventName($event);
		$isOptional = $optional || (bool) ($options['optional'] ?? false);
		$binding = new EventBinding($this, $name, $options, $isOptional);

		if ($listener !== null) {
			$this->addAfterListener($name, $listener, $options, $isOptional);
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

		$beforeListeners = $this->beforeListeners[$name] ?? [];
		$listeners = $this->listeners[$name] ?? [];
		$afterListeners = $this->afterListeners[$name] ?? [];

		$this->eventHistory[] = [
			'event' => $name,
			'payload' => $payload,
		];

		$this->eventStack[] = $name;

		try {
			foreach ($beforeListeners as $item) {
				$this->invoke($item, $name, $payload, 'init.before');
			}

			foreach ($listeners as $item) {
				$this->invoke($item, $name, $payload, 'init');
			}

			foreach ($afterListeners as $item) {
				$this->invoke($item, $name, $payload, 'init.after');
			}
		} catch (Throwable $e) {
			throw new InitException($name, $this->eventStack, $e);
		} finally {
			array_pop($this->eventStack);
		}

		return $payload;
	}

	public function addBeforeListener(string $event, callable $listener, array $options = [], bool $optional = false): void
	{
		$this->addStageListener($event, $listener, InitStage::Before, $optional || (bool) ($options['optional'] ?? false));
	}

	public function addListener(string $event, callable $listener, array $options = [], bool $optional = false): void
	{
		$this->addStageListener($event, $listener, InitStage::On, $optional || (bool) ($options['optional'] ?? false));
	}

	public function addAfterListener(string $event, callable $listener, array $options = [], bool $optional = false): void
	{
		$this->addStageListener($event, $listener, InitStage::After, $optional || (bool) ($options['optional'] ?? false));
	}

	public function addDoneListener(string $event, callable $listener, array $options = [], bool $optional = false): void
	{
		$this->addAfterListener($event, $listener, $options, $optional);
	}

	public function addStageListener(string $event, callable $listener, InitStage $stage, bool $optional = false): void
	{
		$owner = $this->currentExtension ?? $this->profiler?->getActiveExtension();

		if ($owner !== null) {
			$this->subscriptions[$owner][] = [
				'event' => $event,
				'optional' => $optional,
			];
		}

		$entry = [
			'owner' => $owner,
			'callback' => $listener,
			'optional' => $optional,
		];

		match ($stage) {
			InitStage::Before => $this->beforeListeners[$event][] = $entry,
			InitStage::On => $this->listeners[$event][] = $entry,
			InitStage::After => $this->afterListeners[$event][] = $entry,
		};
	}

	public function registerExtension(ExtensionInterface $extension): void
	{
		$previous = $this->currentExtension;
		$this->currentExtension = $extension::class;

		try {
			$this->registerAttributeHooks($extension, InitAttributeLoader::extractHooks($extension));
			$extension->register($this);
		} finally {
			$this->currentExtension = $previous;
		}
	}

	/**
	 * Registers declarative attribute hooks for an extension instance.
	 *
	 * @param list<array{method: string, event: string, stage: string, optional: bool}> $hooks
	 */
	public function registerAttributeHooks(object $extension, array $hooks): void
	{
		foreach ($hooks as $hook) {
			$stage = InitStage::from($hook['stage']);
			$this->addStageListener(
				$hook['event'],
				[$extension, $hook['method']],
				$stage,
				$hook['optional'],
			);
		}
	}

	/**
	 * Removes optional listeners registered for unavailable events.
	 *
	 * @param list<string> $events
	 */
	public function removeOptionalListenersForEvents(array $events): void
	{
		if ($events === []) {
			return;
		}

		$eventSet = array_flip($events);

		foreach (['beforeListeners', 'listeners', 'afterListeners'] as $bucket) {
			foreach ($this->{$bucket} as $event => $items) {
				if (! isset($eventSet[$event])) {
					continue;
				}

				$remaining = array_values(array_filter(
					$items,
					static fn (array $item): bool => ! $item['optional']
				));

				if ($remaining === []) {
					unset($this->{$bucket}[$event]);
				} else {
					$this->{$bucket}[$event] = $remaining;
				}
			}
		}
	}

	/**
	 * Sorts listeners based on the provided extension order.
	 * Extensions later in the order will have their listeners run later.
	 *
	 * @param list<string> $orderedClasses
	 */
	public function sortListeners(array $orderedClasses): void
	{
		$orderMap = array_flip($orderedClasses);
		$defaultOrder = count($orderedClasses);

		$sortFn = static function (array $a, array $b) use ($orderMap, $defaultOrder): int {
			$orderA = $orderMap[$a['owner']] ?? $defaultOrder;
			$orderB = $orderMap[$b['owner']] ?? $defaultOrder;

			return $orderA <=> $orderB;
		};

		foreach ($this->beforeListeners as &$listeners) {
			usort($listeners, $sortFn);
		}
		unset($listeners);

		foreach ($this->listeners as &$listeners) {
			usort($listeners, $sortFn);
		}
		unset($listeners);

		foreach ($this->afterListeners as &$listeners) {
			usort($listeners, $sortFn);
		}
		unset($listeners);
	}

	/**
	 * @return array<string, list<array{event: string, optional: bool}>>
	 */
	public function getSubscriptions(): array
	{
		return $this->subscriptions;
	}

	/**
	 * @return array<string, list<string>> [ExtensionClass => [EventName]]
	 */
	public function getSubscriptionMap(): array
	{
		$map = [];
		foreach ($this->subscriptions as $owner => $entries) {
			$map[$owner] = array_map(
				static fn (array $entry): string => $entry['event'],
				$entries
			);
		}

		return $map;
	}

	/** @return string[] */
	public function getEventStack(): array
	{
		return $this->eventStack;
	}

	/** @return array<int, array{event: string, payload: object}> */
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

	/**
	 * @param array{owner: ?string, callback: callable, optional: bool} $item
	 */
	private function invoke(array $item, string $event, object $payload, string $stage): mixed
	{
		$listener = $item['callback'];
		$owner = $item['owner'];

		if ($this->profiler !== null && $owner !== null) {
			$this->profiler->begin(
				$owner,
				$event,
				$stage,
				$listener
			);

			try {
				return $listener($payload, $this->context);
			} finally {
				$this->profiler->end();
			}
		}

		return $listener($payload, $this->context);
	}
}
