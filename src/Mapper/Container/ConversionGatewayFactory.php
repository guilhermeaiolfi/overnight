<?php

declare(strict_types=1);

namespace ON\Mapper\Container;

use ON\Mapper\ConversionGateway;
use ON\Mapper\MapperConfig;
use Psr\Container\ContainerInterface;

final class ConversionGatewayFactory
{
	public function __invoke(ContainerInterface $container): ConversionGateway
	{
		$config = $container->has(MapperConfig::class)
			? $container->get(MapperConfig::class)
			: new MapperConfig();

		$gateway = ConversionGateway::create($config, $container);
		ConversionGateway::setInstance($gateway);

		return $gateway;
	}
}
