<?php

declare(strict_types=1);

namespace ON\Event;

use ON\Container\Executor\ExecutorInterface;
use Psr\Container\ContainerInterface;

class LazyListener
{
	public function __construct(
		protected ContainerInterface $container,
		protected $callback
	) {

	}

	public function __invoke($event)
	{

		[ $className, $method ] = $this->callback;
		$executor = $this->container->get(ExecutorInterface::class);
		$instance = $this->container->get($className);
		$executor->execute(
			[
				$instance,
				$method,
			],
			[
				get_class($event) => $event,
			]
		);
	}
}
