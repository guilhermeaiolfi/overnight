<?php

declare(strict_types=1);

namespace ON\DB;

/**
 * Holds named database instances for the active execution context.
 *
 * Container-managed DatabaseManager instances share the container's store.
 */
interface DatabaseStoreInterface
{
	public function get(string $name): ?DatabaseInterface;

	public function set(string $name, DatabaseInterface $database): void;

	/**
	 * Run a callback inside an isolated scope when not already inside one,
	 * restoring any pre-existing instances and closing only those opened
	 * during the outer scope.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function scoped(callable $callback): mixed;

	public function close(): void;
}
