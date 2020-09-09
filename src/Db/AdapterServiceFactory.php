<?php
namespace ON\Db;

use Psr\Container\ContainerInterface;
use Laminas\Db\Adapter\Adapter;

class AdapterServiceFactory
{
    /**
     * Create db adapter service
     *
     * @param  ContainerInterface $container
     * @return Adapter
     */
    public function __invoke(ContainerInterface $container)
    {
        $config = $container->get('config');
        return new Adapter($config['db']);
    }
}