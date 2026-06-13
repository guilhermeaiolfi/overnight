<?php

declare(strict_types=1);

namespace ON\RestApi\Hook;

use ON\Container\Executor\ExecutorInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
use ON\RestApi\Support\AuthorizationGuard;
use Psr\Container\ContainerInterface;

final class RestHookDispatcher
{
	public function __construct(
		private ContainerInterface $container,
		private ExecutorInterface $executor,
	) {}

	public function start(): RestHookTransaction
	{
		return new RestHookTransaction($this);
	}

	public function dispatch(object $payload, bool $assertAuthorization = true): object
	{
		$definitions = $this->definitions($this->collectionFromPayload($payload), $payload);
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
	private function definitions(CollectionInterface $collection, object $payload): array
	{
		$hooks = $collection->metadata(RestHooks::METADATA_KEY) ?? [];
		$definitions = $hooks[ltrim($payload::class, '\\')] ?? [];
		usort(
			$definitions,
			static fn (array $left, array $right): int => ($right['priority'] ?? 0) <=> ($left['priority'] ?? 0)
		);

		return $definitions;
	}

	private function collectionFromPayload(object $payload): CollectionInterface
	{
		if (method_exists($payload, 'getCollection')) {
			$collection = $payload->getCollection();
			if ($collection instanceof CollectionInterface) {
				return $collection;
			}
		}

		throw new \InvalidArgumentException(sprintf(
			'RestApi hook payload of type %s must provide getCollection(): CollectionInterface.',
			$payload::class
		));
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
