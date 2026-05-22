<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

final class AliasRegistry
{
	/** @var array<string, int> */
	private array $counts = [];

	public function alias(string $base): string
	{
		$base = preg_replace('/[^A-Za-z0-9_]/', '_', $base);
		$base = trim((string) $base);
		$base = $base === '' ? 'alias' : $base;

		$count = $this->counts[$base] ?? 0;
		$this->counts[$base] = $count + 1;

		return $count === 0 ? $base : $base . '_' . $count;
	}
}
