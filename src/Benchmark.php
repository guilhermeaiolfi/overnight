<?php

declare(strict_types=1);

namespace ON;

class Benchmark
{
	public static array $benchmarks = [];

	public static function start(string $event): void
	{
		static::$benchmarks[$event] = microtime(true);
	}

	public static function end(string $event): void
	{
		static::$benchmarks[$event] = (microtime(true) - static::$benchmarks[$event]) * 1000;
	}

	public static function ms($event): float
	{
		return static::$benchmarks[$event];
	}

	public static function has($event): bool
	{
		return isset(static::$benchmarks[$event]);
	}

	public static function all(): array
	{
		return static::$benchmarks;
	}
}
