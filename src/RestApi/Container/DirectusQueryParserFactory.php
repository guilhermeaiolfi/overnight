<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\RestApiConfig;
use Psr\Container\ContainerInterface;

final class DirectusQueryParserFactory
{
	public function __invoke(ContainerInterface $container): DirectusQueryParser
	{
		$config = $container->get(RestApiConfig::class);

		return new DirectusQueryParser(
			defaultLimit: (int) $config->get('defaultLimit', 100),
			maxLimit: (int) $config->get('maxLimit', 1000)
		);
	}
}
