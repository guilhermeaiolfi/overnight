<?php

declare(strict_types=1);

namespace ON\Db;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class ManagerFactory
{
    public function __invoke(ContainerInterface $container) : Manager
    {
        $config   = $container->has('config') ? $container->get('config') : [];

        $settings = $config["db"];

        $manager = new Manager($settings, $container);

        $manager->setEventDispatcher($container->get(EventDispatcherInterface::class));        

        return $manager;
    }
}
