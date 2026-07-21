<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\Data\DataRuntime;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use Psr\Container\ContainerInterface;

class DirectusQueryParserFactory
{
	public function __invoke(ContainerInterface $container): DirectusQueryParser
	{
		return new DirectusQueryParser($container->get(DataRuntime::class));
	}
}
