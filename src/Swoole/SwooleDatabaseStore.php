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

	private ?DatabaseStore $fallback = null;

	public function __construct()
	{
		$this->contextKey = self::class . ':' . (++self::$nextInstanceId);
	}

	public function get(string $name): ?DatabaseInterface
	{
		return $this->currentStore(create: false)?->get($name);
	}

	public function set(string $name, DatabaseInterface $database): void
	{
		$this->currentStore(create: true)->set($name, $database);
	}

	public function close(): void
	{
		$this->currentStore(create: false)?->close();
	}

	private function currentStore(bool $create): ?DatabaseStore
	{
		if (class_exists(Coroutine::class, false) && Coroutine::getCid() > 0) {
			$context = Coroutine::getContext();
			if ($context !== null) {
				if (! isset($context[$this->contextKey])) {
					if (! $create) {
						return null;
					}

					$store = $context[$this->contextKey] = new DatabaseStore();
					$key = $this->contextKey;
					Coroutine::defer(static function () use ($context, $key, $store): void {
						unset($context[$key]);
						$store->close();
					});
				}

				return $context[$this->contextKey];
			}
		}

		if (! $create) {
			return $this->fallback;
		}

		return $this->fallback ??= new DatabaseStore();
	}
}
