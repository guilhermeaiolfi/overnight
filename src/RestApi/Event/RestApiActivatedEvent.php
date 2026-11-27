<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Init\Attribute\InitEvent;
use ON\RestApi\RestApiConfig;
use ON\RestApi\RestApiExtension;
use Psr\Container\ContainerInterface;

#[InitEvent(owner: RestApiExtension::class, lazy: true)]
final class RestApiActivatedEvent
{
	public function __construct(
		public readonly ContainerInterface $container,
		public readonly RestApiConfig $config,
		public readonly ?string $endpointUri = null,
	) {
	}
}
