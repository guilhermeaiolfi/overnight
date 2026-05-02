<?php

declare(strict_types=1);

namespace ON\Event;

use ON\Config\Init\Event\ConfigConfigureEvent;

use Exception;
use League\Event\ListenerPriority;
use ON\Application;
use ON\Config\AppConfig;


use ON\Discovery\AttributesDiscoverer;
use ON\Event\Attribute\EventHandlerAttributeProcessor;
use ON\Event\Event\ReadyEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Init\InitContext;
use ON\Container\Init\Event\ContainerReadyEvent;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class EventsExtension extends AbstractExtension
{
	public const ID = 'events';

	public const NAMESPACE = "core.extensions.events";
	protected int $type = self::TYPE_EXTENSION;

	/** @var EventDispatcher */
	public EventDispatcherInterface $eventDispatcher;
	protected ContainerInterface $container;

	protected array $q = [];

	protected array $__states = [ ];

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
		$this->eventDispatcher = new EventDispatcher();
	}
	public function register(Init $init): void
	{
		if ($this->app->hasExtension('container')) {
			$init->on(ContainerReadyEvent::class, [ $this, 'onContainerReady' ]);
			$init->on(ConfigConfigureEvent::class, [ $this, 'onConfigConfigure' ]);
		}
	}

	public function onConfigConfigure(): void
	{
		$appCfg = $this->app->config->get(AppConfig::class);
		$appCfg->set("discovery.discoverers." . AttributesDiscoverer::class . ".processors." . EventHandlerAttributeProcessor::class, []);
	}

	public function start(InitContext $context): void
	{
		// register events for extensions
		$this->registerEventSubscribersForExtensions();

		$this->flush();
	}

	public function onContainerReady(ContainerReadyEvent $event): void
	{
		$this->container = $event->container;
		$this->container->set(EventDispatcherInterface::class, $this->eventDispatcher);
		$this->dispatch(new ReadyEvent());
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
		}
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
						$instance = $this->container->get($callback);
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
			$instance = $this->container->get($class);
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

	public function registerListener(string $event, mixed $listener, $priority = ListenerPriority::NORMAL, bool $once = false)
	{
		if (! is_callable($listener)) {
			throw new Exception("Listener is not callable for event \"" . $event . "\"");
		}
		if ($once) {
			$this->eventDispatcher->subscribeOnceTo($event, $listener, $priority);
		} else {
			$this->eventDispatcher->subscribeTo($event, $listener, $priority);
		}
	}
}
