<?php

declare(strict_types=1);

namespace ON\DB;

use Psr\Container\ContainerInterface;

interface DatabaseInterface
{
	public function getConnection(): mixed;

	public function getResource(): mixed;

	public function setName(string $name): void;

	public function getName(): string;
}
