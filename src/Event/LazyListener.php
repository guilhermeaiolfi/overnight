<?php

declare(strict_types=1);

namespace ON\Event;

use ON\Application;
use ON\Container\Executor\ExecutorInterface;

class LazyListener
{
	public function __construct(
		protected Application $app,
		protected $callback
	) {

	}

	public function __invoke($event)
	{

		[ $className, $method ] = $this->callback;
		$executor = $this->app->container->get(ExecutorInterface::class);
		$instance = $this->app->container->get($className);
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
