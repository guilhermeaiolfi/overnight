<?php

declare(strict_types=1);

namespace ON\DB;

interface DatabaseContextInterface
{
	/** Reuse one scope per execution and close it on exit. Return null outside a managed execution. */
	public function current(): ?DatabaseScope;
}
