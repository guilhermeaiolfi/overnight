<?php

declare(strict_types=1);

namespace ON\ORM\Container;

use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use ON\ORM\Compiler\CycleRegistryGenerator;
use Psr\Container\ContainerInterface;

final class CycleRegistryGeneratorFactory
{
	public function __invoke(ContainerInterface $container): CycleRegistryGenerator
	{
		return new CycleRegistryGenerator(
			$container->get(Registry::class),
			$container->get(ConversionGateway::class),
		);
	}
}
