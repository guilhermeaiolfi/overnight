<?php

declare(strict_types=1);

namespace ON\RestApi\Hook;

use ON\Container\Executor\ExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
use ON\RestApi\Support\AuthorizationGuard;
use Psr\Container\ContainerInterface;

final class RestHookDispatcher
{
	public function __construct(
		private ContainerInterface $container,
		private ExecutorInterface $executor,
	) {
	}

	public function start(): RestHookTransaction
	{
		return new RestHookTransaction($this);
	}

	public function dispatch(CollectionInterface $collection, string $slot, object $payload, bool $assertAuthorization = true): object
	{
		$definitions = $this->definitions($collection, $slot);
		foreach ($definitions as $definition) {
			$this->executor->execute($this->resolveHandler($definition['handler'] ?? null), [
				get_class($payload) => $payload,
				'event' => $payload,
				'hook' => $payload,
				'payload' => $payload,
			]);
		}

		if ($assertAuthorization && $payload instanceof AuthorizationAwareEventInterface) {
			AuthorizationGuard::assert($payload);
		}

		return $payload;
	}

	/**
	 * @return list<array{handler: mixed, priority: int}>
	 */
	private function definitions(CollectionInterface $collection, string $slot): array
	{
		$hooks = $collection->metadata(RestHooks::METADATA_KEY) ?? [];
		$definitions = $hooks[$slot] ?? [];
		usort(
			$definitions,
			static fn (array $left, array $right): int => ($right['priority'] ?? 0) <=> ($left['priority'] ?? 0)
		);

		return $definitions;
	}

	private function resolveHandler(mixed $handler): mixed
	{
		if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0])) {
			return [$this->container->get($handler[0]), $handler[1]];
		}

		if (is_string($handler) && class_exists($handler)) {
			return $this->container->get($handler);
		}

		return $handler;
	}
}
