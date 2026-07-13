<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Key;
use RuntimeException;

/**
 * RestApi primary-key helper over an ON\Data collection.
 *
 * Translates collection PK metadata into RestApi identity parsing / URL encoding.
 * Concrete identities are {@see Key} values from ON\Data.
 */
final class PrimaryKey
{
	/**
	 * @param list<FieldInterface> $fields
	 */
	public function __construct(
		private CollectionInterface $collection,
		private array $fields
	) {
	}

	public static function of(CollectionInterface $collection): self
	{
		return new self($collection, $collection->getPrimaryKeyFields());
	}

	/**
	 * @return list<FieldInterface>
	 */
	public function getFields(): array
	{
		return $this->fields;
	}

	/**
	 * @return list<string>
	 */
	public function getFieldNames(): array
	{
		return array_map(static fn (FieldInterface $field): string => $field->getName(), $this->fields);
	}

	/**
	 * @return list<string>
	 */
	public function getColumns(): array
	{
		return array_map(static fn (FieldInterface $field): string => $field->getColumn(), $this->fields);
	}

	public function isComposite(): bool
	{
		return count($this->fields) > 1;
	}

	public function extractFromInput(array $input, bool $allowColumnNames = true): ?Key
	{
		return $this->extract($input, $allowColumnNames);
	}

	public function extractFromRow(array $row, bool $allowColumnNames = true): ?Key
	{
		return $this->extract($row, $allowColumnNames);
	}

	public function requireFromInput(array $input, string $context): Key
	{
		$value = $this->extractFromInput($input);
		if ($value !== null) {
			return $value;
		}

		$missing = $this->getMissingFieldNames($input);
		$fieldList = implode(', ', $missing);

		throw new InvalidArgumentException("{$context} requires primary key field(s): {$fieldList}.");
	}

	/**
	 * @return list<string>
	 */
	public function getMissingFieldNames(array $input): array
	{
		$missing = [];

		foreach ($this->fields as $field) {
			if (
				! array_key_exists($field->getName(), $input)
				&& ! array_key_exists($field->getColumn(), $input)
			) {
				$missing[] = $field->getName();
			}
		}

		return $missing;
	}

	public function getValueFromUrlId(string $id): Key
	{
		if (! $this->isComposite()) {
			$fieldName = $this->getFieldNames()[0] ?? 'id';

			return new Key($this->collection, [$fieldName => $id]);
		}

		$decoded = base64_decode(strtr($id, '-_', '+/') . str_repeat('=', (4 - strlen($id) % 4) % 4), true);
		if ($decoded === false) {
			throw new InvalidArgumentException('Invalid composite primary key encoding.');
		}

		$data = json_decode($decoded, true);
		if (! is_array($data)) {
			throw new InvalidArgumentException('Invalid composite primary key payload.');
		}

		$value = $this->extract($data, false);
		if ($value === null) {
			throw new InvalidArgumentException('Incomplete composite primary key payload.');
		}

		return $value;
	}

	public function getValue(Key|array|string|int|float|bool $value): Key
	{
		return $this->normalizeValue($value);
	}

	public function toUrlId(Key $identity): string
	{
		if ($identity->getCollection()->getName() !== $this->collection->getName()) {
			throw new InvalidArgumentException(sprintf(
				"Primary key belongs to collection '%s', expected '%s'.",
				$identity->getCollection()->getName(),
				$this->collection->getName(),
			));
		}

		if (! $this->isComposite()) {
			return (string) $identity->getValue();
		}

		$json = json_encode($identity->getValues(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			throw new RuntimeException('Unable to encode composite primary key.');
		}

		return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function extract(array $input, bool $allowColumnNames): ?Key
	{
		$values = [];

		foreach ($this->fields as $field) {
			$name = $field->getName();
			if (array_key_exists($name, $input)) {
				$value = $input[$name];
			} elseif ($allowColumnNames && array_key_exists($field->getColumn(), $input)) {
				$value = $input[$field->getColumn()];
			} else {
				return null;
			}

			if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
				return null;
			}

			$values[$name] = $value;
		}

		return new Key($this->collection, $values);
	}

	private function normalizeValue(Key|array|string|int|float|bool $value): Key
	{
		if ($value instanceof Key) {
			return $this->collection->getKey($value);
		}

		if (is_array($value)) {
			$identity = $this->extract($value, true);
			if ($identity !== null) {
				return $identity;
			}

			if (! $this->isComposite() && array_is_list($value) && count($value) === 1) {
				$scalar = $value[0];
				if (! is_string($scalar) && ! is_int($scalar) && ! is_float($scalar) && ! is_bool($scalar)) {
					throw new InvalidArgumentException('Invalid primary key value array.');
				}

				return new Key($this->collection, [$this->getFieldNames()[0] => $scalar]);
			}

			throw new InvalidArgumentException('Invalid primary key value array.');
		}

		if ($this->isComposite()) {
			return $this->getValueFromUrlId((string) $value);
		}

		return new Key($this->collection, [$this->getFieldNames()[0] => $value]);
	}
}
