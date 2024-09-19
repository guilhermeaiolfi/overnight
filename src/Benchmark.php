<?php

namespace ON;

class Benchmark {
    public static array $benchmarks = [];

    public static function start(string $event): void
    {
        self::$benchmarks[$event] = microtime(true);
    }

    public static function end(string $event): void
    {
        self::$benchmarks[$event] = (microtime(true) - self::$benchmarks[$event]) * 1000;
    }

    public static function ms($event): float {
        return self::$benchmarks[$event] * 1000;
    }

    public static function all(): array {
        return self::$benchmarks;
    }
}