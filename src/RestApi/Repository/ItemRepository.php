<?php

declare(strict_types=1);

namespace ON\RestApi\Repository;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Query\SelectQuery;
use Cycle\Database\StatementInterface as CycleStatementInterface;
use ON\Mapper\Exception\ConversionException;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Mutation\OperationQueue;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\Support\PrimaryKeyCriteria;

use function ON\Mapper\map;

class ItemRepository implements ItemRepositoryInterface
{
	public function __construct(
		private Registry $registry,
		private DatabaseInterface $database,
		private int $defaultLimit = 100,
		private int $maxLimit = 1000,
	) {
	}

	public function select(CollectionInterface|string $collection, array $fieldNames = []): SelectQuery
	{
		$collection = is_string($collection) ? $this->registry->getCollection($collection) : $collection;
		$query = $this->database->select()->from($collection->getTable());
		$columns = $this->buildSelectColumnNames($collection, $fieldNames);

		if ($columns !== null) {
			$query->columns($columns);
		}

		return $query;
	}

	public function fetchAll(SelectQuery $query): array
	{
		return $query->fetchAll(CycleStatementInterface::FETCH_ASSOC);
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		$statement = $query->run();
		try {
			$row = $statement->fetch(CycleStatementInterface::FETCH_ASSOC);
		} finally {
			$statement->close();
		}

		return is_array($row) ? $row : null;
	}

	public function count(SelectQuery $query): int
	{
		return (int) (clone $query)->count();
	}

	public function findByIdentity(
		CollectionInterface $collection,
		PrimaryKeyValue|string $identity,
		string $output = PhpRepresentation::class,
	): ?array {
		$row = $this->loadByIdentity($collection, $identity);

		if ($row === null) {
			return null;
		}

		try {
			return map($row)
				->using(CollectionRowMapper::class, $collection)
				->from(StorageRepresentation::class)
				->as($output)
				->toArray();
		} catch (ConversionException $e) {
			throw RestApiError::validationFailed([
				$e->getField() ?? '_root' => [$e->getMessage()],
			]);
		}
	}

	public function create(CollectionInterface $collection, array $input): ?array
	{
		try {
			$storageInput = $this->mapInputToColumns($collection, $input);
			$primaryKeyValue = $collection->getPrimaryKey()->extract($storageInput);
			$lastId = $this->database->insert($collection->getTable())
				->values($storageInput)
				->run();

			$id = $primaryKeyValue;
			if ($id === null && $lastId !== null && ! $collection->getPrimaryKey()->isComposite()) {
				$id = $collection->getPrimaryKey()->extract([
					$collection->getPrimaryKey()->getFieldNames()[0] => $lastId,
				], false);
			}

			if ($id === null) {
				return [];
			}

			$row = $this->loadByIdentity($collection, $id);

			return $row === null ? [] : $row;
		} catch (RestApiError $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function update(CollectionInterface $collection, FilterNode $criteria, array $input): ?array
	{
		try {
			$storageInput = $input;

			if ($storageInput === []) {
				$row = $this->firstByCriteria($collection, $criteria);

				return $row === null ? null : $row;
			}

			$query = $this->database->update($collection->getTable())
				->values($this->mapInputToColumns($collection, $storageInput));
			$this->applyCriteriaFilter($query, $collection, $criteria);
			$query->run();

			$row = $this->firstByCriteria($collection, $criteria);

			return $row === null ? null : $row;
		} catch (RestApiError $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function delete(CollectionInterface $collection, FilterNode $criteria): ?array
	{
		try {
			$row = $this->firstByCriteria($collection, $criteria);
			if ($row === null) {
				return null;
			}

			$query = $this->database->delete($collection->getTable());
			$this->applyCriteriaFilter($query, $collection, $criteria);

			return $query->run() > 0 ? $row : null;
		} catch (\Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function commit(OperationQueue $queue, callable $resolve): mixed
	{
		return $this->database->transaction(function () use ($queue, $resolve): mixed {
			$queue->execute($this);

			return $resolve();
		});
	}

	public function getDatabase(): DatabaseInterface
	{
		return $this->database;
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
				->using(CollectionRowMapper::class, $collection)
				->from(PhpRepresentation::class)
				->as(StorageRepresentation::class)
				->toArray();
		} catch (ConversionException $e) {
			throw RestApiError::validationFailed([
				$e->getField() ?? '_root' => [$e->getMessage()],
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

	protected function applyCriteriaFilter(object $query, CollectionInterface $collection, FilterNode $criteria): void
	{
		(new SqlQuerySpecCompiler($this->database, $this->defaultLimit, $this->maxLimit))
			->applyFilters($query, $collection, $criteria);
	}

	protected function loadByIdentity(CollectionInterface $collection, PrimaryKeyValue|string $identity): ?array
	{
		$query = $this->select($collection, $collection->getVisibleFields());
		PrimaryKeyCriteria::applyWhere($query, $collection, $identity);
		$query->limit(1);
		$row = $this->fetchOne($query);

		return $row !== null ? $collection->mapVisibleRowFromColumns($row) : null;
	}


	protected function firstByCriteria(CollectionInterface $collection, FilterNode $criteria): ?array
	{
		$query = $this->select($collection);
		$this->applyCriteriaFilter($query, $collection, $criteria);
		$query->limit(1);
		$row = $this->fetchOne($query);

		return $row !== null ? $collection->mapVisibleRowFromColumns($row) : null;
	}

	protected function convertDatabaseError(\Throwable $e, CollectionInterface $collection): RestApiError
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
