<?php

declare(strict_types=1);

namespace ON\Extension;

use Exception;
use League\Event\ListenerPriority;
use ON\Application;
use ON\Event\EventDispatcher;
use ON\Event\EventSubscriberInterface;
use ON\Event\NamedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

class EventsExtension extends AbstractExtension implements EventSubscriberInterface
{
	public const NAMESPACE = "core.extensions.events";
	protected int $type = self::TYPE_EXTENSION;

	/** @var EventDispatcher */
	public EventDispatcherInterface $eventDispatcher;

	protected array $q = [];

	protected array $pendingTasks = [ "container:define" ];

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public static function install(Application $app, ?array $options = []): mixed
	{
		// we can't use the container since it may not be started yet
		$extension = new self($app, $options);
		$app->registerExtension('events', $extension); // register shortcut
		$app->events = $extension;

		return $extension;
	}

	public function requires(): array
	{
		return [];
	}

	public function setup(int $counter): bool
	{
		$this->eventDispatcher = new EventDispatcher();

		// register events for extensions
		$this->registerEventSubscribersForExtensions();

		return true;
	}

	public function registerEventSubscribersForExtensions()
	{
		$exts = $this->app->getInstalledExtensions();
		foreach ($exts as $ext) {
			$class = $ext;
			if (is_array($ext)) {
				$class = $ext["class"];
			}
			$obj = $this->app->ext($class);
			if ($obj) {
				$this->loadEventSubscriber($obj);
			}
			$this->dispatch(new NamedEvent("core.ready", $obj ?? $class));
		}
	}

	public function ready()
	{
		// clear the queue of events to dispatch
		$this->flush();
	}

	protected function flush()
	{
		while ($event = array_shift($this->q)) {
			$this->dispatch($event);
		}
	}

	public function dispatch($event, $data = null)
	{
		if (is_string($event)) {
			$event = new NamedEvent($event, $data);
		}
		if (isset($this->eventDispatcher)) {
			return $this->eventDispatcher->dispatch($event);
		}
		$this->q[] = $event;
	}

	/**
	 * listeners: [
	 *      EventSubscriberClass::class
	 *      "foo.bar" => [
	 *          CallableClass::class,
	 *          [
	 *              AnotherCallableClass::class,
	 *              12 // priority
	 *          ]
	 *      ]
	 * ]
	 */
	protected function configureListeners(array $listeners = [])
	{

		//var_dump($listeners);exit;
		if (! is_array($listeners)) {
			throw new Exception("The listerners config is not an array");
		}

		foreach ($listeners as $key => $listener) {
			if (is_string($key)) {
				if (! is_array($listener)) {
					throw new Exception("The listeners object for named events ({$key}) must be an array.");
				}
				foreach ($listener as $callback) {
					$priority = ListenerPriority::NORMAL;
					;
					if (is_array($callback)) {
						$priority = $callback[1];
						$callback = $callback[0];
					}
					if (is_string($callback)) {
						$instance = $this->app->container->get($callback);
						if (! is_callable($instance)) {
							throw new Exception("Event manager can't handle class of type {$callback}");

							return;
						}
						$this->registerListener($key, $instance, $priority);
					}
				}
			} else {
				$this->loadEventSubscriber($listener);
			}
		}
	}

	public function loadEventSubscriber(mixed $class)
	{

		$instance = $class;

		if (is_string($class)) {
			if (! class_exists($class)) {
				throw new Exception("Class {$class} doesn't exist when trying to register events");
			}
			$instance = $this->app->container->get($class);
		}

		if ($instance instanceof EventSubscriberInterface) {
			$events = $instance->getSubscribedEvents();
			foreach ($events as $key => $method) {
				$priority = ListenerPriority::NORMAL;
				if (is_array($method)) {
					$priority = $method[1];
					$method = $method[0];
				}
				$this->registerListener($key, [$instance, $method], $priority);
			}
		}
	}

	public function registerListener($event, mixed $listener, $priority = ListenerPriority::NORMAL)
	{
		if (! is_callable($listener)) {
			throw new Exception("Listener is not callable for event \"" . $event . "\"");
		}
		if (! is_string($event)) {
			throw new Exception("Event name (" . $event . ") is not valid for listener: " . get_class($listener));
		}
		$this->eventDispatcher->subscribeTo($event, $listener, $priority);
	}

	public function onContainerReady($event): void
	{
		$this->app->container->set(EventDispatcherInterface::class, $this->eventDispatcher);
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.extensions.container.ready' => 'onContainerReady',
		];
	}
}
