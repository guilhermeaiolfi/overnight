<?php

declare(strict_types=1);

namespace ON\Swoole;

use ON\DB\DatabaseInterface;
use ON\DB\DatabaseManager;
use ON\DB\DatabasePool;
use ON\DB\DatabasePoolInterface;
use Swoole\Coroutine;

final class SwooleDatabasePool implements DatabasePoolInterface
{
	public function getOrCreate(DatabaseManager $manager, string $name, callable $create): ?DatabaseInterface
	{
		return $this->coroutinePool()?->getOrCreate($manager, $name, $create);
	}

	public function openRequest(): bool
	{
		return $this->coroutinePool()?->openRequest() ?? false;
	}

	public function closeRequest(): void
	{
		$this->coroutinePool()?->closeRequest();
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
