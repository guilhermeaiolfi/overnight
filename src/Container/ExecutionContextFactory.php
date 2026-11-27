<?php

declare(strict_types=1);

namespace ON\Container;

use ON\ExecutionContextInterface;
use ON\Swoole\SwooleExecutionContext;
use Psr\Container\ContainerInterface;

final class ExecutionContextFactory
{
	public static function resolve(ContainerInterface $container): ExecutionContextInterface
	{
		return $container->has(ExecutionContextInterface::class)
			? $container->get(ExecutionContextInterface::class)
			: new SwooleExecutionContext();
	}
}
