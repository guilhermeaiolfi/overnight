<?php

namespace ON\Extension;

use Exception;
use League\Event\EventDispatcher;
use League\Event\ListenerPriority;
use ON\Application;
use Psr\EventDispatcher\EventDispatcherInterface;
use ON\Event\EventSubscriberInterface;

class EventsExtension extends AbstractExtension
{
    protected int $type = self::TYPE_EXTENSION;
    public function __construct(
        protected Application $app,
        public EventDispatcherInterface $eventDispatcher
    ) {

    }

    public static function install(Application $app, ?array $options = []): mixed {
        $container = Application::getContainer();
        $extension = $container->get(self::class);
        $app->registerExtension('events', $extension); // register shortcut

        $config = $container->get('config');
        $extension->configureListeners($config["listeners"]);

        $extension->registerEventSubscribersForExtensionsBeforeThis();
        return $extension;
    }

    public function registerEventSubscribersForExtensionsBeforeThis() {
        $exts = $this->app->getInstalledExtensions();
        foreach ($exts as $ext) {
            $class = $ext;
            if (is_array($ext)) {
                $class = $ext["class"];
            }
            if ($class == self::class) {
                break;
            }
            $obj = $this->app->ext($class);
            $this->loadEventSubscriber($obj);
        }
    }

    public function dispatch($event) {
        return $this->eventDispatcher->dispatch($event);
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
    protected function configureListeners(array $listeners = []) {
        
        //var_dump($listeners);exit;
        if (!is_array($listeners)) {
            throw new Exception("The listerners config is not an array");
        }

        foreach ($listeners as $key => $listener) {
            if (is_string($key)) {
                if (!is_array($listener)) {
                    throw new Exception("The listeners object for named events ({$key}) must be an array.");
                }
                foreach ($listener as $callback) {
                    $priority = ListenerPriority::NORMAL;;
                    if (is_array($callback)) {
                        $priority = $callback[1];
                        $callback = $callback[0];
                    }
                    if (is_string($callback)) {
                        $instance = $this->app->getContainer()->get($callback);
                        if (!is_callable($instance)) {
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

    public function loadEventSubscriber(mixed $class) {
   
        $instance = $class;

        if (is_string($class)) {
            if (!class_exists($class)) {
                throw new Exception("Class {$class} doesn't exist when trying to register events");
            }
            $instance = $this->app->getContainer()->get($class);
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

    public function registerListener($event, mixed $listener, $priority = ListenerPriority::NORMAL) {
        if (!is_callable($listener)) {
            throw new Exception("Listener is not callable for event \"" . $event . "\"");
        }
        if (!is_string($event)) {
            throw new Exception("Event name (" . $event . ") is not valid for listener: " . $class?? $listener);
        }
        $this->eventDispatcher->subscribeTo($event, $listener, $priority);
    }
}
