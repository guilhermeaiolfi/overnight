<?php

declare(strict_types=1);

namespace ON\Db;

use Laminas\Db\Adapter\Adapter;
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
