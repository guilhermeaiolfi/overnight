<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL\Support;

use ON\DB\DatabaseInterface;

class InMemoryDatabase implements DatabaseInterface
{
	private int $lastInsertId = 0;

	public function __construct(
		private array $tables = []
	) {
	}

	public function addTable(string $name, array $rows): void
	{
		$this->tables[$name] = $rows;
	}

	public function getConnection(): mixed
	{
		return new InMemoryConnection($this->tables, $this->lastInsertId);
	}

	public function getResource(): mixed
	{
		return $this->getConnection();
	}

	public function setName(string $name): void
	{
	}

	public function getName(): string
	{
		return 'in-memory';
	}
}
