<?php

declare(strict_types=1);

namespace ON\Swoole;

use ON\DB\DatabaseInterface;
use ON\DB\DatabaseStore;
use ON\DB\DatabaseStoreInterface;
use Swoole\Coroutine;

final class SwooleDatabaseStore implements DatabaseStoreInterface
{
	private static int $nextInstanceId = 0;

	private string $contextKey;

	private string $deferKey;

	private ?DatabaseStore $fallback = null;

	public function __construct()
	{
		$id = ++self::$nextInstanceId;
		$this->contextKey = self::class . ':' . $id;
		$this->deferKey = $this->contextKey . ':defer';
	}

	public function get(string $name): ?DatabaseInterface
	{
		return $this->currentStore(create: false)?->get($name);
	}

	public function set(string $name, DatabaseInterface $database): void
	{
		$this->currentStore(create: true, deferClose: true)->set($name, $database);
	}

	public function scoped(callable $callback): mixed
	{
		return $this->currentStore(create: true)->scoped($callback);
	}

	public function close(): void
	{
		$this->currentStore(create: false)?->close();
	}

	private function currentStore(bool $create, bool $deferClose = false): ?DatabaseStore
	{
		if (class_exists(Coroutine::class, false) && Coroutine::getCid() > 0) {
			$context = Coroutine::getContext();
			if ($context !== null) {
				if (! isset($context[$this->contextKey])) {
					if (! $create) {
						return null;
					}

					$context[$this->contextKey] = new DatabaseStore();
				}

				$store = $context[$this->contextKey];
				if ($deferClose && ! isset($context[$this->deferKey])) {
					$context[$this->deferKey] = true;
					$key = $this->contextKey;
					$deferKey = $this->deferKey;
					Coroutine::defer(static function () use ($context, $key, $deferKey, $store): void {
						unset($context[$key], $context[$deferKey]);
						$store->close();
					});
				}

				return $store;
			}
		}

		if (! $create) {
			return $this->fallback;
		}

		return $this->fallback ??= new DatabaseStore();
	}
}
