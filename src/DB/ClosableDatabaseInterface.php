<?php

declare(strict_types=1);

namespace ON\DB;

interface ClosableDatabaseInterface extends DatabaseInterface
{
	public function close(): void;
}
