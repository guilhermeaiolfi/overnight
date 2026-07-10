<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\RestApi\Query\DirectusQueryBuilder;
use ON\RestApi\Query\QueryNormalizer;
use ON\RestApi\RestApiConfig;
use Psr\Container\ContainerInterface;

final class DirectusQueryBuilderFactory
{
	public function __invoke(ContainerInterface $container): DirectusQueryBuilder
	{
		$config = $container->get(RestApiConfig::class);

		return new DirectusQueryBuilder(
			new QueryNormalizer($config->get('dynamicVariables', [])),
		);
	}
}
