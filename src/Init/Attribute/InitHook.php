<?php

declare(strict_types=1);

namespace ON\Init\Attribute;

use ON\Init\InitStage;

abstract readonly class InitHook
{
	public function __construct(
		public ?string $event = null,
		public bool $optional = false,
	) {
	}

	abstract public function stage(): InitStage;
}
