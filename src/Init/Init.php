<?php

declare(strict_types=1);

namespace ON\Init;

use BackedEnum;
use ON\Extension\ExtensionInterface;
use ON\Extension\ExtensionProfiler;
use Throwable;

class Init
{
	/** @var array<string, array<string, list<array{owner: ?string, callback: callable, optional: bool}>>> */
	private array $listeners = [];

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

	public function before(object|string $event, ?callable $listener = null, array $options = [], ?bool $optional = null): EventBinding
	{
		return $this->bind(InitStage::Before, $event, $listener, $options, $optional);
	}

	public function on(object|string $event, ?callable $listener = null, array $options = [], ?bool $optional = null): EventBinding
	{
		return $this->bind(InitStage::On, $event, $listener, $options, $optional);
	}

	public function after(object|string $event, ?callable $listener = null, array $options = [], ?bool $optional = null): EventBinding
	{
		return $this->bind(InitStage::After, $event, $listener, $options, $optional);
	}

	private function bind(InitStage $stage, object|string $event, ?callable $listener, array $options, ?bool $optional): EventBinding
	{
		$binding = new EventBinding($this, $this->normalizeEventName($event), $options, $optional, $stage);

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

		$this->eventHistory[] = [
			'event' => $name,
			'payload' => $payload,
		];

		$this->eventStack[] = $name;

		try {
			foreach (InitStage::cases() as $stage) {
				$profileStage = $stage === InitStage::On ? 'init' : 'init.' . $stage->value;
				foreach ($listeners[$stage->value] ?? [] as $item) {
					$this->invoke($item, $name, $payload, $profileStage);
				}
			}
		} catch (Throwable $e) {
			throw new InitException($name, $this->eventStack, $e);
		} finally {
			array_pop($this->eventStack);
		}

		return $payload;
	}

	public function addBeforeListener(string $event, callable $listener, array $options = [], ?bool $optional = null): void
	{
		$this->addStageListener($event, $listener, InitStage::Before, $optional ?? (bool) ($options['optional'] ?? false));
	}

	public function addListener(string $event, callable $listener, array $options = [], ?bool $optional = null): void
	{
		$this->addStageListener($event, $listener, InitStage::On, $optional ?? (bool) ($options['optional'] ?? false));
	}

	public function addAfterListener(string $event, callable $listener, array $options = [], ?bool $optional = null): void
	{
		$this->addStageListener($event, $listener, InitStage::After, $optional ?? (bool) ($options['optional'] ?? false));
	}

	public function addDoneListener(string $event, callable $listener, array $options = [], ?bool $optional = null): void
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

		$this->listeners[$event][$stage->value][] = [
			'owner' => $owner,
			'callback' => $listener,
			'optional' => $optional,
		];
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
		foreach ($events as $event) {
			foreach ($this->listeners[$event] ?? [] as $stage => $items) {
				$this->listeners[$event][$stage] = array_values(array_filter(
					$items,
					static fn (array $item): bool => ! $item['optional']
				));
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

		foreach ($this->listeners as $event => $stages) {
			foreach ($stages as $stage => $items) {
				usort($items, $sortFn);
				$this->listeners[$event][$stage] = $items;
			}
		}
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
