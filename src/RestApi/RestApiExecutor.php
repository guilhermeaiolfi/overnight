<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\Application;
use ON\Container\Executor\ExecutorInterface;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Event\RestApiActivatedEvent;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class RestApiExecutor implements RestApiExecutorInterface
{
	private bool $activated = false;

	public function __construct(
		private ContainerInterface $container,
		private RestApiConfig $config,
		private EventDispatcherInterface $dispatcher,
		private ?Application $app = null,
	) {
	}

	public function execute(
		string|RestActionInterface $action,
		array $params = [],
		mixed $payload = null,
		?array $options = null,
	): mixed {
		$this->ensureActivated();

		if (is_string($action)) {
			$action = $this->container->get($this->resolveActionClass($action));
		}

		return $this->container->get(ExecutorInterface::class)->execute($action, [
			'params' => $params,
			'payload' => $payload,
			'options' => $options,
		]);
	}

	public function ensureActivated(): void
	{
		if ($this->activated) {
			return;
		}

		$this->activated = true;
		$event = new RestApiActivatedEvent(
			$this->container,
			$this->config,
			(string) $this->config->get('endpointUri', '/items'),
		);

		$this->app?->init()->emit($event);
		$this->dispatcher->dispatch($event);
	}

	private function resolveActionClass(string $action): string
	{
		if (class_exists($action)) {
			return $action;
		}

		foreach ($this->config->get('actions', []) as $registeredAction) {
			if (($registeredAction['name'] ?? null) === $action) {
				return $registeredAction['action'];
			}
		}

		return $action;
	}
}
