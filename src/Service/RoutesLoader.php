<?php
namespace ON\Service;

use ON\Application;
use ON\Container\ApplicationConfigInjectionDelegator;
use ON\Event\EventSubscriberInterface;
use Psr\Container\ContainerInterface;

class RoutesLoader {
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
}