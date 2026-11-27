<?php

declare(strict_types=1);

namespace ON\Swoole;

use ON\DB\DatabaseInterface;
use ON\DB\DatabasePool;
use ON\DB\DatabasePoolInterface;
use Swoole\Coroutine;

final class SwooleDatabasePool implements DatabasePoolInterface
{
	private ?DatabasePool $fallback = null;

	public function get(object $owner, string $name): ?DatabaseInterface
	{
		return $this->databasePool()->get($owner, $name);
	}

	public function set(object $owner, string $name, DatabaseInterface $database): void
	{
		$this->databasePool()->set($owner, $name, $database);
	}

	public function openRequest(): bool
	{
		return $this->databasePool()->openRequest();
	}

	public function closeRequest(): void
	{
		$this->databasePool()->closeRequest();
	}

	private function databasePool(): DatabasePool
	{
		return $this->coroutinePool() ?? ($this->fallback ??= new DatabasePool());
	}

	private function coroutinePool(): ?DatabasePool
	{
		if (! class_exists(Coroutine::class, false) || Coroutine::getCid() <= 0) {
			return null;
		}

		$context = Coroutine::getContext();
		if ($context === null) {
			return null;
		}

		if (! isset($context[self::class])) {
			$pool = $context[self::class] = new DatabasePool();
			Coroutine::defer(static function () use ($context, $pool): void {
				unset($context[self::class]);
				$pool->close();
			});
		}

		return $context[self::class];
	}
}
