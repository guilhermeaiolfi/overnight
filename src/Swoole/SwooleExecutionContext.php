<?php

declare(strict_types=1);

namespace ON\Swoole;

use ON\ExecutionContextInterface;
use ON\ScopedResourceInterface;
use SplObjectStorage;
use Swoole\Coroutine;

final class SwooleExecutionContext implements ExecutionContextInterface
{
	public function isActive(): bool
	{
		return class_exists(Coroutine::class, false) && Coroutine::getCid() > 0;
	}

	public function getScopedResource(object $owner, callable $create): ?ScopedResourceInterface
	{
		if (! $this->isActive()) {
			return null;
		}

		$context = Coroutine::getContext();
		if ($context === null) {
			return null;
		}

		$resources = $context[self::class] ??= new SplObjectStorage();
		if (isset($resources[$owner])) {
			return $resources[$owner];
		}

		$resource = $resources[$owner] = $create();
		Coroutine::defer(static function () use ($resources, $owner, $resource): void {
			unset($resources[$owner]);
			$resource->release();
		});

		return $resource;
	}
}
