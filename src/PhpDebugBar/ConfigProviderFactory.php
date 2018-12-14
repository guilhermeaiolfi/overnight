<?php
namespace ON\PhpDebugBar;

use PhpMiddleware\PhpDebugBar\ConfigProvider;
use Psr\Container\ContainerInterface;

class ConfigProviderFactory
{
    public function __invoke(ContainerInterface $container) : ConfigProvider
    {
        return new ConfigProvider();
    }
}