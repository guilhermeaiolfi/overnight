<?php

declare(strict_types=1);

namespace ON\Swoole;

use ON\ExecutionContextInterface;
use Swoole\Coroutine;

final class SwooleExecutionContext implements ExecutionContextInterface
{
	public function getId(): ?string
	{
		$id = class_exists(Coroutine::class, false) ? Coroutine::getCid() : -1;

		return $id > 0 ? (string) $id : null;
	}

	public function onExit(callable $callback): void
	{
		Coroutine::defer($callback);
	}
}
