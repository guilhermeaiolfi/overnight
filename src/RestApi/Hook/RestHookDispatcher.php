<?php

declare(strict_types=1);

namespace ON\RestApi\Hook;

use function array_values;
use function count;
use ON\Container\Executor\ExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
use ON\RestApi\Support\AuthorizationGuard;
use Psr\Container\ContainerInterface;
use function usort;
use WeakMap;

final class RestHookDispatcher
{
	/** @var WeakMap<CollectionInterface, array<string, array{source: array<int|string, mixed>, sorted: list<array{handler: mixed, priority: int}>}>> */
	private WeakMap $sortedCache;

	public function __construct(
		private ContainerInterface $container,
		private ExecutorInterface $executor,
	) {
		$this->sortedCache = new WeakMap();
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

		if (count($definitions) <= 1) {
			return array_values($definitions);
		}

		$collectionCache = $this->sortedCache[$collection] ?? [];
		if (isset($collectionCache[$slot]) && $collectionCache[$slot]['source'] === $definitions) {
			return $collectionCache[$slot]['sorted'];
		}

		$sorted = $definitions;
		usort(
			$sorted,
			static fn (array $left, array $right): int => ($right['priority'] ?? 0) <=> ($left['priority'] ?? 0)
		);

		$collectionCache[$slot] = [
			'source' => $definitions,
			'sorted' => $sorted,
		];
		$this->sortedCache[$collection] = $collectionCache;

		return $sorted;
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
