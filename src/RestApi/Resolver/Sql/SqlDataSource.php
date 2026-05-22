<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\DatabaseInterface as CycleDatabaseInterface;
use Cycle\Database\Query\SelectQuery;
use Cycle\Database\StatementInterface as CycleStatementInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Resolver\AbstractDataSource;

class SqlDataSource extends AbstractDataSource
{
	public function __construct(
		protected Registry $registry,
		protected CycleDatabaseInterface $database,
		int $defaultLimit = 100,
		int $maxLimit = 1000
	) {
		parent::__construct($defaultLimit, $maxLimit);
	}

	public function transaction(callable $callback): mixed
	{
		return $this->getDatabase()->transaction($callback);
	}

	public function create(CollectionInterface $collection, array $input): array
	{
		try {
			$primaryKeyValue = $this->inputPrimaryKeyValue($collection, $input);
			$input = $this->mapInputToColumns($collection, $input);
			$lastId = $this->getDatabase()->insert($collection->getTable())
				->values($input)
				->run();

			$id = $lastId ?? $primaryKeyValue;

			return $id === null ? [] : ($this->getVisibleById($collection, (string) $id) ?? []);
		} catch (RestApiError $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function update(CollectionInterface $collection, FilterNode $criteria, array $input): ?array
	{
		try {
			if ($input === []) {
				return $this->firstByCriteria($collection, $criteria);
			}

			$query = $this->getDatabase()->update($collection->getTable())
				->values($this->mapInputToColumns($collection, $input));
			$this->applyCriteriaFilter($query, $collection, $criteria);
			$query->run();

			return $this->firstByCriteria($collection, $criteria);
		} catch (RestApiError $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function delete(CollectionInterface $collection, FilterNode $criteria): bool
	{
		try {
			$query = $this->getDatabase()->delete($collection->getTable());
			$this->applyCriteriaFilter($query, $collection, $criteria);

			return $query->run() > 0;
		} catch (\Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function clearCache(): void
	{
	}

	public function select(CollectionInterface|string $collection, array $fieldNames = []): SelectQuery
	{
		$collection = is_string($collection) ? $this->registry->getCollection($collection) : $collection;
		$query = $this->getDatabase()->select()->from($collection->getTable());
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

	public function getVisibleById(CollectionInterface $collection, string $id): ?array
	{
		$query = $this->select($collection, $this->visibleFieldNames($collection));
		$query->where($this->getPrimaryKeyColumn($collection), $id)->limit(1);
		$row = $this->fetchOne($query);
		if ($row === null) {
			return null;
		}

		$item = [];
		foreach ($collection->fields as $fieldName => $field) {
			if ($field->isHidden()) {
				continue;
			}

			$column = $field->getColumn();
			if (array_key_exists($column, $row)) {
				$item[(string) $fieldName] = $row[$column];
			}
		}

		return $item;
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

	public function getDatabase(): CycleDatabaseInterface
	{
		return $this->database;
	}

	public function getRegistry(): Registry
	{
		return $this->registry;
	}

	protected function mapInputToColumns(CollectionInterface $collection, array $input): array
	{
		$mapped = [];
		foreach ($input as $fieldName => $value) {
			$fieldName = (string) $fieldName;
			if (!$collection->fields->has($fieldName)) {
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

	protected function firstByCriteria(CollectionInterface $collection, FilterNode $criteria): ?array
	{
		$query = $this->select($collection);
		$this->applyCriteriaFilter($query, $collection, $criteria);
		$query->limit(1);
		$row = $this->fetchOne($query);

		return $row !== null ? $this->mapRowToFields($collection, $row) : null;
	}

	protected function mapRowToFields(CollectionInterface $collection, array $row): array
	{
		$item = [];
		foreach ($collection->fields as $fieldName => $field) {
			if ($field->isHidden()) {
				continue;
			}

			$column = $field->getColumn();
			if (array_key_exists($column, $row)) {
				$item[(string) $fieldName] = $row[$column];
			}
		}

		return $item;
	}

	protected function convertDatabaseError(\Throwable $e, CollectionInterface $collection): RestApiError
	{
		$message = $e->getMessage();

		if (str_contains($message, 'UNIQUE constraint') || str_contains($message, 'Duplicate entry')) {
			if (preg_match('/column[s]?\s+[`\']?(\w+)/i', $message, $matches)) {
				$field = $this->columnToFieldName($collection, $matches[1]);
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

		if (str_contains($message, 'NOT NULL constraint') || str_contains($message, 'cannot be null')) {
			if (preg_match('/[`\']?(\w+)[`\']?\s+(?:cannot be null|NOT NULL)/i', $message, $matches)) {
				$field = $this->columnToFieldName($collection, $matches[1]);
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

		if (str_contains($message, 'FOREIGN KEY constraint')) {
			return new RestApiError('Referenced record does not exist.', 'FOREIGN_KEY_VIOLATION', null, 400, $e);
		}

		return new RestApiError('Database operation failed.', 'DATABASE_ERROR', null, 500, $e);
	}

	protected function columnToFieldName(CollectionInterface $collection, string $column): string
	{
		return $collection->fields->hasColumn($column)
			? $collection->fields->getKeyByColumnName($column)
			: $column;
	}

	protected function inputPrimaryKeyValue(CollectionInterface $collection, array $input): mixed
	{
		$primaryKeyName = $this->getPrimaryKeyName($collection);

		if (array_key_exists($primaryKeyName, $input)) {
			return $input[$primaryKeyName];
		}

		$primaryKeyColumn = $this->getPrimaryKeyColumn($collection);

		return $input[$primaryKeyColumn] ?? null;
	}

	protected function getPrimaryKeyName(CollectionInterface $collection): string
	{
		$pk = $collection->getPrimaryKey();

		if (is_array($pk)) {
			$pk = reset($pk);
		}

		return $pk instanceof \ON\ORM\Definition\Field\FieldInterface ? $pk->getName() : 'id';
	}

	private function visibleFieldNames(CollectionInterface $collection): array
	{
		$fieldNames = [];
		foreach ($collection->fields as $fieldName => $field) {
			if (!$field->isHidden()) {
				$fieldNames[] = (string) $fieldName;
			}
		}

		return $fieldNames;
	}

}
