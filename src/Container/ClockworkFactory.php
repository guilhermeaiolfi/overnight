<?php

declare(strict_types=1);

namespace ON\Container;

use Clockwork\Support\Vanilla\Clockwork;
use ON\Clockwork\DataSource\PsrLoggerDatasource;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ClockworkFactory
{
    public function __invoke(ContainerInterface $container) : Clockwork
    {
        $config   = $container->has('config') ? $container->get('config') : [];

        $settings = $config["clockwork"];

        $clockwork = Clockwork::init($settings);

        $logger = $container->get(LoggerInterface::class);
        $loggerDataSource = new PsrLoggerDatasource($logger);
        $clockwork->addDataSource($loggerDataSource);
        return $clockwork;
    }
}
