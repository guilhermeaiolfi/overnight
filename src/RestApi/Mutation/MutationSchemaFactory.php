<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\Mapper\Exception\ConversionException;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Session;
use function ON\Data\Query\x;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Support\PrimaryKey;
use stdClass;

/**
 * Compiles RepresentationSchema shapes for RestApi Session writes.
 *
 * Prefer writable SelectQuery loads for existing rows (schema comes from the query).
 * Use {@see projectFields()} only for creates and field overlays absent from a load select.
 */
final class MutationSchemaFactory
{
	public function __construct(
		private readonly DataRuntime $runtime,
		private readonly ItemRepositoryInterface $items,
	) {
	}

	/**
	 * @param list<string> $fieldNames
	 */
	public function projectFields(CollectionInterface $collection, array $fieldNames): RepresentationSchema
	{
		$query = $this->runtime->query($collection);
		$names = array_values(array_unique(array_filter(
			$fieldNames,
			static fn (string $name): bool => $collection->hasField($name),
		)));

		if ($names === []) {
			return RepresentationSchema::forPrimaryKey($collection);
		}

		$refs = [];
		foreach ($names as $name) {
			$refs[] = $query->field($name);
		}

		return $query->select(...$refs)->projection();
	}

	/**
	 * Load an existing row into the mutation Session via writable SelectQuery.
	 *
	 * @param list<string>|null $fieldNames null = all visible fields
	 */
	public function loadWritable(
		Session $session,
		CollectionInterface $collection,
		Key $identity,
		?array $fieldNames = null,
	): object {
		$pkFields = PrimaryKey::of($collection)->getFieldNames();
		$selected = $fieldNames ?? $collection->getVisibleFields();
		$selected = array_values(array_unique([...$pkFields, ...$selected]));

		$query = $this->items->select($collection, $selected);
		foreach ($identity->getValues() as $fieldName => $value) {
			$query->where(x()->eq($query->field((string) $fieldName), $value));
		}
		$query->limit(1);

		$object = $query
			->to(stdClass::class)
			->writable($session)
			->fetchOne();

		if (! is_object($object)) {
			throw RestApiError::notFound();
		}

		return $object;
	}

	/**
	 * Convert Directus/wire scalar payloads to PHP representation for Session writes.
	 *
	 * Nested relation bodies are not converted by Create/UpdateAction root mapping.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	public function toPhpValues(CollectionInterface $collection, array $values): array
	{
		$scalars = [];
		foreach ($values as $field => $value) {
			$name = (string) $field;
			if ($collection->hasField($name)) {
				$scalars[$name] = $value;
			}
		}
		if ($scalars === []) {
			return [];
		}

		try {
			return map($scalars)
				->args($collection)
				->from(WireRepresentation::class)
				->as(PhpRepresentation::class)
				->to([]);
		} catch (ConversionException) {
			return map($scalars)
				->args($collection)
				->from(PhpRepresentation::class)
				->as(PhpRepresentation::class)
				->to([]);
		}
	}
}
