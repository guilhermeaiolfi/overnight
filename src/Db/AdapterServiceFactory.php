<?php

declare(strict_types=1);

namespace ON\DB;

use Laminas\DB\Adapter\Adapter;
use Psr\Container\ContainerInterface;

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
