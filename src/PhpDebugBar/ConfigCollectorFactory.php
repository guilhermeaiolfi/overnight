<?php
declare (strict_types=1);

namespace ON\PhpDebugBar;

use DebugBar\DataCollector\ConfigCollector;
use Psr\Container\ContainerInterface;
use PhpMiddleware\PhpDebugBar\ConfigProvider;

final class ConfigCollectorFactory
{
    public function __invoke(ContainerInterface $container): ConfigCollector
    {
        $data = $container->get('config');

        return new ConfigCollector($data->all(), 'Config');
    }
}
