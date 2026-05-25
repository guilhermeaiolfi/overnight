<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\RestApi\Query\QueryNormalizer;
use ON\RestApi\RestApiConfig;
use Psr\Container\ContainerInterface;

final class QueryNormalizerFactory
{
	public function __invoke(ContainerInterface $container): QueryNormalizer
	{
		$config = $container->get(RestApiConfig::class);

		return new QueryNormalizer($config->get('dynamicVariables', []));
	}
}
