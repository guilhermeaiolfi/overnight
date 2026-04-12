<?php

declare(strict_types=1);

namespace ON\GraphQL;

use GraphQL\Type\Schema;
use ON\Config\Config;
use ON\ORM\Definition\Registry;
use Psr\Container\ContainerInterface;

class GraphQLSchemaFactory
{
	public function __construct(
		protected ContainerInterface $container
	) {
	}

	public function create(Config $config): Schema
	{
		$ormRegistry = $this->container->get(Registry::class);

		$generator = new GraphQLRegistryGenerator($ormRegistry, $this->container);

		return $generator->generate();
	}
}
