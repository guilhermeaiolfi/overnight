<?php

declare(strict_types=1);

namespace ON\GraphQL\DataLoader;

use GraphQL\Deferred;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Registry;

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

		if (isset($this->cache[$key])) {
			return $this->cache[$key];
		}

		$this->pendingKeysByEntity[$entity][$key] = true;

		return new Deferred(function () use ($entity, $keys, $key, $loaderFn) {
			$items = $this->loadPendingBatch($entity, $loaderFn);

			$normalized = $this->normalizeKey($entity, $keys);
			return $items[$normalized] ?? null;
		});
	}

	public function loadMany(string $entity, array $keys, callable $loaderFn): mixed
	{
		$deferredResults = [];

		foreach ($keys as $key) {
			$result = $this->load($entity, $key, $loaderFn);
			$deferredResults[$this->normalizeKey($entity, $key)] = $result;
		}

		return new Deferred(function () use ($entity, $loaderFn, $deferredResults, $keys) {
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
		$keys = $this->pendingKeysByEntity[$entity] ?? [];
		foreach (array_keys($keys) as $key) {
			unset($this->cache[$key]);
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
		if (! is_array($keys)) {
			$keys = ['id' => $keys];
		}

		$pkColumns = $this->getPrimaryKeyColumns($entity);

		if (count($pkColumns) === 1 && isset($keys['id'])) {
			return "{$entity}:{$keys['id']}";
		}

		$sorted = [];
		foreach ($pkColumns as $col) {
			if (array_key_exists($col, $keys)) {
				$sorted[$col] = $keys[$col];
			}
		}

		if (empty($sorted)) {
			$sorted = $keys;
		}

		\ksort($sorted);

		$parts = [];
		foreach ($sorted as $col => $value) {
			$parts[] = $col . ':' . $value;
		}

		return $entity . ':' . implode(':', $parts);
	}

	protected function decodeKey(string $entity, string $key): array
	{
		$pkColumns = $this->getPrimaryKeyColumns($entity);
		$remainder = substr($key, strlen($entity) + 1);
		$parts = explode(':', $remainder);

		// Single PK: key is either "value" or "col:value"
		if (count($pkColumns) === 1) {
			$pkColumn = $pkColumns[0];
			$value = count($parts) === 1 ? $parts[0] : $parts[1];
			return [
				'entity' => $entity,
				'keys' => [$pkColumn => $value],
			];
		}

		// Composite PK: pairs of "col:value"
		$keys = [];
		for ($i = 0; $i < count($parts); $i += 2) {
			if (isset($parts[$i + 1])) {
				$keys[$parts[$i]] = $parts[$i + 1];
			}
		}

		return [
			'entity' => $entity,
			'keys' => $keys,
		];
	}

	protected function getPrimaryKeyColumns(string $entity): array
	{
		if (!isset($this->registry->collections[$entity])) {
			return ['id'];
		}

		$collection = $this->registry->getCollection($entity);

		$pk = $collection->getPrimaryKey();

		if ($pk instanceof FieldInterface) {
			return [$pk->getColumn()];
		}

		if (is_array($pk)) {
			return array_map(fn($field) => $field->getColumn(), $pk);
		}

		return ['id'];
	}
}
