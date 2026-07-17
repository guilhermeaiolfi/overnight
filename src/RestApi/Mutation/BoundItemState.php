<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Mutation\Payload\PayloadPath;
use ON\RestApi\Support\PrimaryKey;

/**
 * Hook-facing façade over the Session representation the binder will flush.
 *
 * Pending Directus scalars live in an overlay map ({@see getData()}) that is also
 * written onto the representation. After flush, {@see markReady()} merges the
 * reloaded row into that overlay. Hooks never see {@see \ON\Data\ORM\Record\RecordState}.
 *
 * Supported before-hook mutations: scalar field setValue/setData (including fields
 * absent from the original payload). Newly introduced scalars are tracked via
 * {@see pullPendingHookFields()} so the coordinator can overlay them onto the
 * Session schema before sync/flush. Removing a key from setData leaves the prior
 * representation value unchanged (not an explicit null).
 *
 * Unsupported: changing primary-key fields on existing items; mutating relation
 * membership / normalized relation intent (relation names are not scalar fields).
 *
 * Create vs update: use {@see isCreate()}. Do not infer newness from missing PK keys
 * in {@see getData()} — the binder strips identity fields from the overlay for
 * existing related items (e.g. images sent as `{id, sequence}` without `file_id`).
 */
final class BoundItemState implements MutationStateInterface
{
	/**
	 * Pending scalar overlay for hooks (Directus-shaped). Representation is what Session flushes.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	private ?array $row;

	private bool $ready;

	/** @var array<string, string> */
	private array $fieldPaths = [];

	/** @var list<string> */
	private array $pendingHookFields = [];

	/** @var array<string, mixed> Hook-only bag; never written to the representation. */
	private array $metadata = [];

	public function __construct(
		private readonly CollectionInterface $collection,
		private object $representation,
		array $values = [],
		?array $row = null,
		bool $ready = false,
		private readonly PayloadPath $path = new PayloadPath([]),
		array $fieldPaths = [],
		private readonly bool $identityMutable = true,
		private readonly bool $creating = true,
	) {
		$this->values = $values;
		$this->row = $row;
		$this->ready = $ready;
		$this->fieldPaths = $fieldPaths;
		$this->writeRepresentation($values);
	}

	/**
	 * @return list<string>
	 */
	public function pullPendingHookFields(): array
	{
		$fields = $this->pendingHookFields;
		$this->pendingHookFields = [];

		return $fields;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getRepresentation(): object
	{
		return $this->representation;
	}

	public function getPath(): PayloadPath
	{
		return $this->path;
	}

	public function getData(): array
	{
		return $this->values;
	}

	public function setData(array $data): void
	{
		$this->assertIdentityMutable($data);
		$this->values = $data;
		$this->writeRepresentation($data);
	}

	public function getValue(string $column): mixed
	{
		if (array_key_exists($column, $this->values)) {
			return $this->values[$column];
		}

		if ($this->row !== null && array_key_exists($column, $this->row)) {
			return $this->row[$column];
		}

		return null;
	}

	public function setValue(string $column, mixed $value): void
	{
		$this->assertIdentityMutable([$column => $value]);
		$this->values[$column] = $value;
		$this->writeField($column, $value);
	}

	public function isCreate(): bool
	{
		return $this->creating;
	}

	public function isReady(): bool
	{
		return $this->ready;
	}

	public function getRow(): ?array
	{
		return $this->row;
	}

	public function markReady(array $row): void
	{
		$this->row = $row;
		foreach ($row as $field => $value) {
			$name = (string) $field;
			$this->values[$name] = $value;
			$this->writeField($name, $value);
		}
		$this->ready = true;
	}

	public function getKey(bool $requireReady = true): ?Key
	{
		$source = $this->row === null
			? $this->values
			: array_merge($this->row, $this->values);

		$key = PrimaryKey::of($this->collection)->extractFromInput($source);
		if ($key !== null) {
			return $key;
		}

		return $requireReady ? null : PrimaryKey::of($this->collection)->extractFromInput($this->values);
	}

	public function setMetadata(string $key, mixed $value): void
	{
		$this->metadata[$key] = $value;
	}

	public function getMetadata(string $key, mixed $default = null): mixed
	{
		return array_key_exists($key, $this->metadata) ? $this->metadata[$key] : $default;
	}

	public function hasMetadata(string $key): bool
	{
		return array_key_exists($key, $this->metadata);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function writeRepresentation(array $values): void
	{
		foreach ($values as $field => $value) {
			$this->writeField((string) $field, $value);
		}
	}

	private function writeField(string $field, mixed $value): void
	{
		if (! $this->collection->hasField($field)) {
			return;
		}

		if (isset($this->fieldPaths[$field])) {
			$this->representation->{$this->fieldPaths[$field]} = $value;

			return;
		}

		$this->fieldPaths[$field] = $field;
		$this->representation->{$field} = $value;
		$this->trackPendingHookField($field);
	}

	private function trackPendingHookField(string $field): void
	{
		if (! in_array($field, $this->pendingHookFields, true)) {
			$this->pendingHookFields[] = $field;
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function assertIdentityMutable(array $data): void
	{
		if ($this->identityMutable) {
			return;
		}

		$pkFields = PrimaryKey::of($this->collection)->getFieldNames();
		foreach ($pkFields as $fieldName) {
			if (! array_key_exists($fieldName, $data)) {
				continue;
			}
			$current = $this->getValue($fieldName);
			if ($current !== $data[$fieldName]) {
				throw RestApiError::identityMutationNotAllowed($this->path->toString());
			}
		}
	}
}
