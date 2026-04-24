<?php

declare(strict_types=1);

namespace ON\Maintenance\Container;

use ON\Application;
use ON\Maintenance\MaintenanceModeInterface;
use Psr\Container\ContainerInterface;

class MaintenanceModeFactory
{
	public function __invoke(ContainerInterface $container): MaintenanceModeInterface
	{
		$app = $container->get(Application::class);

		return $app->ext('maintenance');
	}
}
