<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\RestApi\Mapping\CollectionMapper;
use Psr\Container\ContainerInterface;

class CollectionMapperFactory
{
	public function __invoke(ContainerInterface $container): CollectionMapper
	{
		return new CollectionMapper();
	}
}
