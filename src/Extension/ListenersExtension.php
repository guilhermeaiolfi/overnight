<?php

namespace ON\Extension;

use Exception;
use League\Event\HasEventName;
use League\Event\ListenerPriority;
use ON\Application;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ListenersExtension implements ExtensionInterface
{
 
    public function __construct(
        protected Application $app
    ) {

    }

    public static function install(Application $app): mixed {
        $container = Application::getContainer();
        $extension = $container->get(self::class);
        $config = $container->get('config');
        if (!isset($app->eventDispatcher)) {
            $app->eventDispatcher = $container->get(EventDispatcherInterface::class);
            $extension->configureListeners($config["listeners"]);
        }
        return null;
    }

    protected function configureListeners(array $listeners = []) {
        
        if (!is_array($listeners)) {
            throw new Exception("The listerners config is not an array");
        }

        foreach ($listeners as $key => $listener) {
            if (is_array($listener) && !is_callable($listener)) {
                foreach ($listener as $callback) {
                    $this->registerListener($key, $callback);
                }
            } else {
                $this->registerListener($key, $listener);
            }
        }
    }

    public function registerListener($event, mixed $listener, $priority = ListenerPriority::NORMAL) {
        if (!is_callable($listener)) {
            $class = $listener;
            if (class_exists($class)) {
                $listener = $this->app->getContainer()->get($listener);
                if ($listener instanceof HasEventName) {
                    $event = $listener->eventName();
                } else if ($listener instanceof EventSubscriberInterface) {
                    $events = $listener->getSubscribedEvents();
                    foreach ($events as $key => $method) {
                        if (is_array($method)) {
                            $priority = $method[1];
                            $method = $method[0];
                        }
                        $this->registerListener($key, [$listener, $method], $priority);
                    }
                    return;
                }
            }
        }
        if (!is_callable($listener)) {
            throw new Exception("Listener is not callable for event \"" . $event . "\"");
        }
        if (!is_string($event)) {
            throw new Exception("Event name (" . $event . ") is not valid for listener: " . $class?? $listener);
        }
        $this->app->eventDispatcher->subscribeTo($event, $listener, $priority);
    }
}
