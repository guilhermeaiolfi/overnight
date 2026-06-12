<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\RestApi\RestApiConfig;
use Psr\Container\ContainerInterface;

final class RestApiActivatedEvent
{
	public function __construct(
		public readonly ContainerInterface $container,
		public readonly RestApiConfig $config,
		public readonly ?string $endpointUri = null,
	) {
	}
}
