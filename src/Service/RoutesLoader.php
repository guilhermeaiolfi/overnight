<?php
namespace ON\Service;

use League\Event\HasEventName;
use ON\Application;
use ON\Container\ApplicationConfigInjectionDelegator;
use Psr\Container\ContainerInterface;

class RoutesLoader implements HasEventName {
    public function __construct (
        protected ContainerInterface $container,
        protected Application $app
    ){

    }

    public function __invoke()
    {
        $config = $this->container->get('config');
        ApplicationConfigInjectionDelegator::injectRoutesFromConfig($this->app, $config->all());
    }

    public function eventName(): string {
        return "app.init";
    }
}