<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use ON\Data\Definition\Collection\CollectionInterface;
use RuntimeException;

/**
 * RestApi-local identity value for a collection primary key.
 */
final class PrimaryKeyValue
{
	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct(
		private CollectionInterface $collection,
		private array $values
	) {
	}

	public function collection(): CollectionInterface
	{
		return $this->collection;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function values(): array
	{
		return $this->values;
	}

	public function value(string $fieldName): mixed
	{
		return $this->values[$fieldName] ?? null;
	}

	public function isComplete(): bool
	{
		foreach (PrimaryKey::of($this->collection)->getFieldNames() as $fieldName) {
			if (! array_key_exists($fieldName, $this->values)) {
				return false;
			}
		}

		return true;
	}

	public function toUrlId(): string
	{
		$primaryKey = PrimaryKey::of($this->collection);

		if (! $primaryKey->isComposite()) {
			$fieldName = $primaryKey->getFieldNames()[0] ?? 'id';

			return (string) ($this->values[$fieldName] ?? '');
		}

		$ordered = [];
		foreach ($primaryKey->getFieldNames() as $fieldName) {
			$ordered[$fieldName] = $this->values[$fieldName] ?? null;
		}

		$json = json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			throw new RuntimeException('Unable to encode composite primary key.');
		}

		return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	public static function fromUrlId(CollectionInterface $collection, string $id): self
	{
		return PrimaryKey::of($collection)->getValueFromUrlId($id);
	}
}
