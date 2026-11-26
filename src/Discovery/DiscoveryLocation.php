<?php

declare(strict_types=1);

namespace ON\Discovery;

class DiscoveryLocation
{
	public function __construct(
		public string $name,
		public array $pattern,
		public string $strategy
	) {

	}

	/**
	 * @param array{name: string, pattern: array<mixed>, strategy: string} $data
	 */
	public static function __set_state(array $data): self
	{
		return new self(
			$data['name'],
			$data['pattern'],
			$data['strategy'],
		);
	}
}
