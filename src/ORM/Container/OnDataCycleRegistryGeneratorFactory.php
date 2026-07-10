<?php

declare(strict_types=1);

namespace ON\ORM\Container;

use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use ON\ORM\Compiler\OnDataCycleRegistryGenerator;
use Psr\Container\ContainerInterface;

final class OnDataCycleRegistryGeneratorFactory
{
	public function __invoke(ContainerInterface $container): OnDataCycleRegistryGenerator
	{
		return new OnDataCycleRegistryGenerator(
			$container->get(Registry::class),
			$container->get(ConversionGateway::class),
		);
	}
}
