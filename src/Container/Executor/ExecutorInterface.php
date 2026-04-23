<?php

declare(strict_types=1);

namespace ON\Container\Executor;

use Psr\Container\ContainerInterface;

interface ExecutorInterface
{
	public function execute($callableOrMethodStr, array $args = []);

	public function getContainer(): ?ContainerInterface;
}
