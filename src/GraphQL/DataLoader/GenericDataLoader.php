<?php

declare(strict_types=1);

namespace ON\GraphQL\DataLoader;

use GraphQL\Deferred;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Registry;

class GenericDataLoader
{
	protected array $cache = [];

	protected array $pending = [];

	protected array $pendingKeysByEntity = [];

	public function __construct(
		protected Registry $registry
	) {
	}

	public function load(string $entity, array|int|string $keys, callable $loaderFn): mixed
	{
		$key = $this->normalizeKey($entity, $keys);

		if (isset($this->cache[$key])) {
			return $this->cache[$key];
		}

		$this->pending[$key] = true;
		$this->pendingKeysByEntity[$entity][$key] = true;

		return new Deferred(function () use ($entity, $keys, $key, $loaderFn) {
			$items = $this->loadPendingBatch($entity, $loaderFn);

			$normalized = $this->normalizeKey($entity, $keys);
			return $items[$normalized] ?? null;
		});
	}

	public function loadMany(string $entity, array $keys, callable $loaderFn): array
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

		return isset($this->cache[$normalized]);
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
	}

	public function clear(): void
	{
		$this->cache = [];
		$this->pending = [];
		$this->pendingKeysByEntity = [];
	}

	public function clearEntity(string $entity): void
	{
		$keys = $this->pendingKeysByEntity[$entity] ?? [];
		foreach (array_keys($keys) as $key) {
			unset($this->cache[$key]);
		}
		unset($this->pendingKeysByEntity[$entity]);
		$this->pending = array_diff_key($this->pending, $keys);
	}

	public function getPendingKeys(string $entity): array
	{
		return array_keys($this->pendingKeysByEntity[$entity] ?? []);
	}

	public function hasPending(string $entity): bool
	{
		return ! empty($this->pendingKeysByEntity[$entity] ?? []);
	}

	protected function loadPendingBatch(string $entity, callable $loaderFn): array
	{
		$keysToLoad = array_keys($this->pendingKeysByEntity[$entity] ?? []);

		if (empty($keysToLoad)) {
			return [];
		}

		$keys = [];
		foreach ($keysToLoad as $key) {
			$decoded = $this->decodeKey($entity, $key);
			$keys[] = $decoded['keys'];
		}

		$loadedItems = $loaderFn($keys);

		foreach ($keysToLoad as $i => $key) {
			$decoded = $this->decodeKey($entity, $key);
			$item = $loadedItems[$i] ?? null;
			$this->cache[$key] = $item;
			unset($this->pending[$key]);
		}

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

		$result = substr($key, strlen($entity) + 1);
		$parts = explode(':', $result);

		if (count($pkColumns) === 1 && count($parts) === 2) {
			$pkColumn = $pkColumns[0];
			return [
				'entity' => $entity,
				'keys' => [$pkColumn => $parts[1]],
			];
		}

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
		$collection = $this->registry->getCollection($entity);

		if (! $collection) {
			return ['id'];
		}

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