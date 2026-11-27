<?php

declare(strict_types=1);

namespace ON;

interface ScopedResourceInterface
{
	/** Release state owned by the scope. */
	public function release(): void;
}
