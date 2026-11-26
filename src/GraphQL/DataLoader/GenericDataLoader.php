<?php

declare(strict_types=1);

namespace ON\GraphQL\DataLoader;

use GraphQL\Deferred;
use function ksort;
use ON\Data\Definition\Registry;

class GenericDataLoader
{
	protected array $cache = [];

	protected array $pendingKeysByEntity = [];

	public function __construct(
		protected Registry $registry,
		protected int $maxCacheSize = 10000
	) {
	}

	public function load(string $entity, array|int|string $keys, callable $loaderFn): mixed
	{
		$key = $this->normalizeKey($entity, $keys);

		if (array_key_exists($key, $this->cache)) {
			return $this->cache[$key];
		}

		$this->pendingKeysByEntity[$entity][$key] = true;

		return new Deferred(function () use ($entity, $key, $loaderFn) {
			$items = $this->loadPendingBatch($entity, $loaderFn);

			return $items[$key] ?? null;
		});
	}

	public function loadMany(string $entity, array $keys, callable $loaderFn): mixed
	{
		foreach ($keys as $key) {
			$this->load($entity, $key, $loaderFn);
		}

		return new Deferred(function () use ($entity, $loaderFn, $keys) {
			$items = $this->loadPendingBatch($entity, $loaderFn);

			$results = [];
			foreach ($keys as $key) {
				$normalized = $this->normalizeKey($entity, $key);
				$results[$key] = $items[$normalized] ?? null;
			}

			return $results;
		});
	}

	public function results(string $entity): array
	{
		$keys = $this->pendingKeysByEntity[$entity] ?? [];

		$results = [];
		foreach (array_keys($keys) as $key) {
			$results[$key] = $this->cache[$key] ?? null;
		}

		return $results;
	}

	public function has(string $entity, array|int|string $key): bool
	{
		$normalized = $this->normalizeKey($entity, $key);

		return array_key_exists($normalized, $this->cache);
	}

	public function get(string $entity, array|int|string $key): mixed
	{
		$normalized = $this->normalizeKey($entity, $key);

		return $this->cache[$normalized] ?? null;
	}

	public function set(string $entity, array|int|string $key, mixed $value): void
	{
		$normalized = $this->normalizeKey($entity, $key);
		$this->cache[$normalized] = $value;

		$this->evictIfNeeded();
	}

	public function clear(): void
	{
		$this->cache = [];
		$this->pendingKeysByEntity = [];
	}

	public function clearEntity(string $entity): void
	{
		$prefix = $entity . "\0";
		foreach (array_keys($this->cache) as $cacheKey) {
			if (str_starts_with($cacheKey, $prefix)) {
				unset($this->cache[$cacheKey]);
			}
		}

		unset($this->pendingKeysByEntity[$entity]);
	}

	public function getPendingKeys(string $entity): array
	{
		return array_keys($this->pendingKeysByEntity[$entity] ?? []);
	}

	public function hasPending(string $entity): bool
	{
		return ! empty($this->pendingKeysByEntity[$entity] ?? []);
	}

	protected function evictIfNeeded(): void
	{
		if (count($this->cache) > $this->maxCacheSize) {
			$evictCount = (int) ($this->maxCacheSize * 0.1);
			$this->cache = array_slice($this->cache, $evictCount, null, true);
		}
	}

	protected function loadPendingBatch(string $entity, callable $loaderFn): array
	{
		$keysToLoad = array_keys($this->pendingKeysByEntity[$entity] ?? []);

		if (empty($keysToLoad)) {
			return $this->cache;
		}

		$keys = [];
		foreach ($keysToLoad as $key) {
			$decoded = $this->decodeKey($entity, $key);
			$keys[] = $decoded['keys'];
		}

		$loadedItems = $loaderFn($keys);

		foreach ($keysToLoad as $i => $key) {
			$item = $loadedItems[$i] ?? null;
			$this->cache[$key] = $item;
		}

		$this->evictIfNeeded();

		unset($this->pendingKeysByEntity[$entity]);

		return $this->cache;
	}

	public function normalizeKey(string $entity, array|int|string $keys): string
	{
		$pkColumns = $this->getPrimaryKeyColumns($entity);

		if (! is_array($keys)) {
			$pkColumn = $pkColumns[0] ?? 'id';
			$normalized = [$pkColumn => (string) $keys];
		} else {
			$sorted = [];
			foreach ($pkColumns as $col) {
				if (array_key_exists($col, $keys)) {
					$sorted[$col] = is_scalar($keys[$col]) ? (string) $keys[$col] : $keys[$col];
				}
			}

			if ($sorted === []) {
				foreach ($keys as $col => $value) {
					$sorted[$col] = is_scalar($value) ? (string) $value : $value;
				}
			}

			ksort($sorted);
			$normalized = $sorted;
		}

		return $this->encodeKey($entity, $normalized);
	}

	protected function encodeKey(string $entity, array $normalizedKeys): string
	{
		return $entity . "\0" . json_encode($normalizedKeys, JSON_THROW_ON_ERROR);
	}

	protected function decodeKey(string $entity, string $key): array
	{
		$payload = substr($key, strlen($entity) + 1);
		$decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

		return [
			'entity' => $entity,
			'keys' => is_array($decoded) ? $decoded : [],
		];
	}

	protected function getPrimaryKeyColumns(string $entity): array
	{
		if (! $this->registry->hasCollection($entity)) {
			return ['id'];
		}

		$collection = $this->registry->getCollection($entity);

		if ($collection === null || ! $collection->hasPrimaryKey()) {
			return ['id'];
		}

		return $collection->getPrimaryKeyColumns();
	}
}
