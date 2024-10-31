<?php

declare(strict_types=1);

namespace ON\Config;

use ON\Db\PdoDatabase;

class DatabaseConnectionConfig
{
	public function __construct(
		protected string $dsn,
		protected ?array $attributes = [],
		protected ?string $username = "",
		protected ?string $password = "",
		protected ?string $class = PdoDatabase::class,
		protected ?string $wrapper_class = null
	) {

	}
}
