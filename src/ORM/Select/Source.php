<?php

declare(strict_types=1);

namespace ON\ORM\Select;

use Cycle\Database\DatabaseInterface;
use Cycle\ORM\Select\ScopeInterface as CycleScopeInterface;
use Cycle\ORM\Select\SourceInterface as CycleSourceInterface;

final class Source implements CycleSourceInterface
{
	private ?ScopeInterface $scope = null;

	public function __construct(
		private DatabaseInterface $database,
		private string $table
	) {
	}

	public function getDatabase(): DatabaseInterface
	{
		return $this->database;
	}

	public function getTable(): string
	{
		return $this->table;
	}

	public function withScope(?CycleScopeInterface $scope): self
	{
		$source = clone $this;
		$source->scope = $scope;

		return $source;
	}

	public function getScope(): ?CycleScopeInterface
	{
		return $this->scope;
	}
}
