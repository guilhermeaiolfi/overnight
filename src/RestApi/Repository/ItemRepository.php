<?php

declare(strict_types=1);

namespace ON\RestApi\Repository;

use Cycle\Database\DatabaseInterface;
use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use ON\Data\Mapper\Exception\ConversionException;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Support\PrimaryKey;
use ON\Data\Key;
use Throwable;

class ItemRepository implements ItemRepositoryInterface
{
	public function __construct(
		private Registry $registry,
		private DataRuntime $runtime,
		private DatabaseInterface $database,
	) {
	}

	public function select(CollectionInterface|string $collection, array $fieldNames = []): SelectQuery
	{
		$collection = is_string($collection) ? $this->registry->getCollection($collection) : $collection;
		$query = $this->runtime->query($collection);
		if ($fieldNames !== []) {
			$query->select(...array_map(
				static fn (string $fieldName) => $query->field($fieldName),
				$fieldNames,
			));
		}

		return $query;
	}

	public function fetchAll(SelectQuery $query): array
	{
		return $query->fetchAll();
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		$row = $query->fetchOne();

		return is_array($row) ? $row : null;
	}

	public function findByIdentity(
		CollectionInterface $collection,
		Key|string $identity,
		string $output = PhpRepresentation::class,
	): ?array {
		$row = $this->loadByIdentity($collection, $identity);

		if ($row === null) {
			return null;
		}

		try {
			return map($row)
				->args($collection)
				->from(PhpRepresentation::class)
				->as($output)
				->to([]);
		} catch (ConversionException $e) {
			throw RestApiError::validationFailed([
				'_root' => [$e->getMessage()],
			]);
		}
	}

	public function create(CollectionInterface $collection, array $input): ?array
	{
		try {
			$session = new \ON\Data\ORM\Session($this->runtime->getCommandExecutor());
			$sessions = new \ON\RestApi\Mutation\SessionFactory($this->runtime);
			$binder = new \ON\RestApi\Mutation\DirectusMutationBinder($this, $this->runtime, $sessions);
			$mutation = (new \ON\RestApi\Mutation\Payload\DirectusPayloadParser())->parse($collection, $input);
			$bound = $binder->bindCreate($session, $collection, $mutation);
			$session->sync($bound->representation);
			$session->flush();

			$representationState = $session->getRepresentations()->get($bound->representation);
			$key = null;
			if ($representationState instanceof \ON\Data\ORM\Representation\State\RepresentationState) {
				$record = $representationState->getSingleRecord();
				if ($record instanceof \ON\Data\ORM\Record\RecordState && $record->hasKey()) {
					$key = $record->getKey();
				}
			}
			if ($key === null && $bound->identity !== null) {
				$key = $bound->identity;
			}
			if ($key === null) {
				return [];
			}

			$row = $this->loadByIdentity($collection, $key);

			return $row === null ? [] : $row;
		} catch (RestApiError $e) {
			throw $e;
		} catch (Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function update(CollectionInterface $collection, array $criteria, array $input): ?array
	{
		try {
			$storageInput = $input;

			if ($storageInput === []) {
				$row = $this->firstByCriteria($collection, $criteria);

				return $row === null ? null : $row;
			}

			$query = $this->database->update($collection->getTable())
				->values($this->mapInputToColumns($collection, $storageInput));
			$this->applyCriteriaWhere($query, $collection, $criteria);
			$query->run();

			$row = $this->firstByCriteria($collection, $criteria);

			return $row === null ? null : $row;
		} catch (RestApiError $e) {
			throw $e;
		} catch (Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function delete(CollectionInterface $collection, array $criteria): bool
	{
		try {
			$query = $this->database->delete($collection->getTable());
			$this->applyCriteriaWhere($query, $collection, $criteria);

			return $query->run() > 0;
		} catch (Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	protected function buildSelectColumnNames(CollectionInterface $collection, ?array $fieldNames): ?array
	{
		if ($fieldNames === null || $fieldNames === []) {
			return null;
		}

		$columnNames = [];
		foreach ($fieldNames as $fieldName) {
			if ($collection->fields->has($fieldName)) {
				$columnNames[] = $collection->fields->get($fieldName)->getColumn();
			}
		}

		return $columnNames === [] ? null : $columnNames;
	}

	protected function mapInputToColumns(CollectionInterface $collection, array $input): array
	{
		try {
			$dehydrated = map($input)
				->args($collection)
				->from(PhpRepresentation::class)
				->as(StorageRepresentation::class)
				->to([]);
		} catch (ConversionException $e) {
			throw RestApiError::validationFailed([
				'_root' => [$e->getMessage()],
			]);
		}

		$mapped = [];
		foreach ($dehydrated as $fieldName => $value) {
			$fieldName = (string) $fieldName;
			if (! $collection->fields->has($fieldName)) {
				throw RestApiError::invalidField($fieldName);
			}

			$mapped[$collection->fields->get($fieldName)->getColumn()] = $value;
		}

		return $mapped;
	}

	protected function applyCriteriaWhere(object $query, CollectionInterface $collection, array $criteria): void
	{
		foreach ($criteria as $fieldName => $value) {
			$column = $collection->fields->get((string) $fieldName)->getColumn();
			if (is_array($value)) {
				$query->where($column, 'in', $value);

				continue;
			}

			$query->where($column, $value);
		}
	}

	protected function loadByIdentity(CollectionInterface $collection, Key|string $identity): ?array
	{
		$key = PrimaryKey::of($collection)->getValue($identity);
		$query = $this->select($collection, $collection->getVisibleFields());
		$this->applyReadCriteria($query, $key->getValues());
		$query->limit(1);

		return $this->fetchOne($query);
	}

	protected function firstByCriteria(CollectionInterface $collection, array $criteria): ?array
	{
		$query = $this->select($collection);
		$this->applyReadCriteria($query, $criteria);
		$query->limit(1);

		return $this->fetchOne($query);
	}

	private function applyReadCriteria(SelectQuery $query, array $criteria): void
	{
		foreach ($criteria as $fieldName => $value) {
			$field = $query->field((string) $fieldName);
			$query->where(is_array($value) ? x()->in($field, $value) : x()->eq($field, $value));
		}
	}

	protected function convertDatabaseError(Throwable $e, CollectionInterface $collection): RestApiError
	{
		$message = $e->getMessage();

		if (str_contains($message, 'UNIQUE constraint') || str_contains($message, 'Duplicate entry')) {
			if (preg_match('/column[s]?\s+[`\']?(\w+)/i', $message, $matches)) {
				$field = $collection->getFieldNameByColumn($matches[1]);

				return new RestApiError(
					"A record with this {$field} already exists.",
					'DUPLICATE',
					$field,
					409,
					$e
				);
			}

			return new RestApiError('A record with these values already exists.', 'DUPLICATE', null, 409, $e);
		}

		if (
			str_contains($message, 'NOT NULL constraint')
			|| str_contains($message, 'cannot be null')
			|| preg_match("/Field '([^']+)' doesn't have a default value/i", $message, $defaultValueMatches)
		) {
			$column = $defaultValueMatches[1] ?? null;
			if ($column === null && preg_match('/[`\']?(\w+)[`\']?\s+(?:cannot be null|NOT NULL)/i', $message, $matches)) {
				$column = $matches[1];
			}

			if ($column !== null) {
				$field = $collection->getFieldNameByColumn($column);

				return new RestApiError(
					"The field {$field} is required.",
					'REQUIRED_FIELD',
					$field,
					400,
					$e
				);
			}

			return new RestApiError('A required field is missing.', 'REQUIRED_FIELD', null, 400, $e);
		}

		if (stripos($message, 'foreign key constraint') !== false) {
			return new RestApiError('Referenced record does not exist.', 'FOREIGN_KEY_VIOLATION', null, 400, $e);
		}

		return new RestApiError('Database operation failed.', 'DATABASE_ERROR', null, 500, $e);
	}
}
