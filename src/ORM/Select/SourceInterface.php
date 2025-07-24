<?php

declare(strict_types=1);

namespace ON\ORM\Select;

use Cycle\Database\DatabaseInterface;
use Cycle\ORM\Select\SourceInterface as CycleSourceInterface;

/**
 * Defines the access to the SQL database.
 */
interface SourceInterface implements CycleSourceInterface
{
	/**
	 * Get database associated with the entity.
	 */
	public function getDatabase(): DatabaseInterface;

	/**
	 * Get table associated with the entity.
	 */
	public function getTable(): string;

	/**
	 * Associate query scope (or remove association).
	 */
	public function withScope(?ScopeInterface $scope): self;

	/**
	 * Return associated query scope.
	 */
	public function getScope(): ?ScopeInterface;
}
