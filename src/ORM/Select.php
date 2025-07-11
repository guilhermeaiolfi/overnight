<?php

declare(strict_types=1);

namespace ON\ORM;

use function array_is_list;
use function array_map;
use function count;
use Countable;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\SelectQuery;
use Cycle\ORM\Heap\HeapInterface;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\Iterator;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\SchemaInterface;
use Cycle\ORM\Service\EntityFactoryInterface;
use Cycle\ORM\Service\MapperProviderInterface;
use InvalidArgumentException;
use function is_array;
use function is_string;
use function iterator_to_array;
use IteratorAggregate;
use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Registry;
use ON\ORM\Select\JoinableLoader;
use ON\ORM\Select\QueryBuilder;
use ON\ORM\Select\RootLoader;
use ON\ORM\Select\ScopeInterface;
use Spiral\Pagination\PaginableInterface;
use function sprintf;

/**
 * Query builder and entity selector. Mocks SelectQuery. Attention, Selector does not mount RootLoader scope by default.
 *
 * Trait provides the ability to transparently configure underlying loader query.
 *
 * @method $this distinct()
 * @method $this where(...$args)
 * @method $this andWhere(...$args);
 * @method $this orWhere(...$args);
 * @method $this having(...$args);
 * @method $this andHaving(...$args);
 * @method $this orHaving(...$args);
 * @method $this orderBy($expression, $direction = 'ASC');
 * @method $this forUpdate()
 * @method $this whereJson(string $path, mixed $value)
 * @method $this orWhereJson(string $path, mixed $value)
 * @method $this whereJsonContains(string $path, mixed $value, bool $encode = true, bool $validate = true)
 * @method $this orWhereJsonContains(string $path, mixed $value, bool $encode = true, bool $validate = true)
 * @method $this whereJsonDoesntContain(string $path, mixed $value, bool $encode = true, bool $validate = true)
 * @method $this orWhereJsonDoesntContain(string $path, mixed $value, bool $encode = true, bool $validate = true)
 * @method $this whereJsonContainsKey(string $path)
 * @method $this orWhereJsonContainsKey(string $path)
 * @method $this whereJsonDoesntContainKey(string $path)
 * @method $this orWhereJsonDoesntContainKey(string $path)
 * @method $this whereJsonLength(string $path, int $length, string $operator = '=')
 * @method $this orWhereJsonLength(string $path, int $length, string $operator = '=')
 * @method mixed avg($identifier) Perform aggregation (AVG) based on column or expression value.
 * @method mixed min($identifier) Perform aggregation (MIN) based on column or expression value.
 * @method mixed max($identifier) Perform aggregation (MAX) based on column or expression value.
 * @method mixed sum($identifier) Perform aggregation (SUM) based on column or expression value.
 *
 * @template-covariant TEntity of object
 */
class Select implements IteratorAggregate, Countable, PaginableInterface
{
	// load relation data within same query
	public const SINGLE_QUERY = JoinableLoader::INLOAD;

	// load related data after the query
	public const OUTER_QUERY = JoinableLoader::POSTLOAD;

	private RootLoader $loader;

	private QueryBuilder $builder;

	//private MapperProviderInterface $mapperProvider;
	private HeapInterface $heap;
	private SchemaInterface $schema;
	private EntityFactoryInterface $entityFactory;

	public function __construct(
		private ORMInterface $orm,
		private Registry $registry,
		private FactoryInterface $factory,
		private Collection $collection
	) {
		$this->heap = $orm->getHeap();
		$this->schema = $orm->getSchema();
		//$this->mapperProvider = $orm->getService(MapperProviderInterface::class);
		$this->entityFactory = $orm->getService(EntityFactoryInterface::class);

		$this->loader = new RootLoader(
			$registry,
			$factory,
			$collection
		);
		//var_dump($factory->mapper($orm, $collection));
		$this->builder = new QueryBuilder($this->loader->getQuery(), $this->loader);
	}

	/**
	 * Remove nested loaders and clean ORM link.
	 */
	public function __destruct()
	{
		unset($this->loader, $this->builder);
	}

	public function getLoader(): RootLoader
	{
		return $this->loader;
	}

	/**
	 * Bypassing call to primary select query.
	 */
	public function __call(string $name, array $arguments): mixed
	{
		if (in_array(strtoupper($name), ['AVG', 'MIN', 'MAX', 'SUM', 'COUNT'])) {
			// aggregations
			return $this->builder->withQuery(
				$this->loader->buildQuery()
			)->__call($name, $arguments);
		}

		$result = $this->builder->__call($name, $arguments);
		if ($result instanceof QueryBuilder) {
			return $this;
		}

		return $result;
	}

	/**
	 * Cloning with loader tree cloning.
	 *
	 * @attention at this moment binded query parameters would't be cloned!
	 */
	public function __clone()
	{
		$this->loader = clone $this->loader;
		$this->builder = new QueryBuilder($this->loader->getQuery(), $this->loader);
	}

	/**
	 * @return Iterator<TEntity>
	 */
	public function getIterator(bool $findInHeap = false): Iterator
	{
		$node = $this->loader->createNode();
		$this->loader->loadData($node, true);

		return Iterator::createWithServices(
			$this->heap,
			$this->schema,
			$this->entityFactory,
			$this->loader->getTarget()->getName(),
			$node->getResult(),
			$findInHeap,
			typecast: true,
		);
	}

	/**
	 * Find one entity or return null. Method provides the ability to configure custom query parameters.
	 *
	 * @return TEntity|null
	 */
	public function fetchOne(?array $query = null): ?object
	{
		$select = (clone $this)->where($query)->limit(1);
		$node = $select->loader->createNode();
		$select->loader->loadData($node, true);
		$data = $node->getResult();

		if (! isset($data[0])) {
			return null;
		}

		/** @var TEntity $result */
		return $this->entityFactory->make($this->loader->getTarget()->getName(), $data[0], Node::MANAGED, typecast: true);
	}

	/**
	 * Fetch all records in a form of array.
	 *
	 * @return list<TEntity>
	 */
	public function fetchAll(): iterable
	{
		return iterator_to_array($this->getIterator(), false);
	}

	/**
	 * Create new Selector with applied scope. By default no scope used.
	 *
	 * @return static<TEntity>
	 */
	public function scope(?ScopeInterface $scope = null): self
	{
		$this->loader->setScope($scope);

		return $this;
	}

	/**
	 * Get Query proxy.
	 */
	public function getBuilder(): QueryBuilder
	{
		return $this->builder;
	}

	/**
	 * Compiled SQL query, changes in this query would not affect Selector state (but bound parameters will).
	 */
	public function buildQuery(): SelectQuery
	{
		return $this->loader->buildQuery();
	}

	/**
	 * Shortcut to where method to set AND condition for entity primary key.
	 *
	 * @psalm-param string|int|list<string|int>|object ...$ids
	 *
	 * @return static<TEntity>
	 */
	public function wherePK(string|int|array|object ...$ids): self
	{
		$pk = $this->loader->getPrimaryFields();

		if (count($pk) > 1) {
			return $this->buildCompositePKQuery($pk, $ids);
		}
		$pk = \current($pk);

		return count($ids) > 1
			? $this->__call('where', [$pk, new Parameter($ids)])
			: $this->__call('where', [$pk, current($ids)]);
	}

	/**
	 * Attention, column will be quoted by driver!
	 *
	 * @param string|null $column When column is null DISTINCT(PK) will be generated.
	 */
	public function count(?string $column = null): int
	{
		if ($column === null) {
			// @tuneyourserver solves the issue with counting on queries with joins.
			$pk = $this->loader->getPK();
			$column = is_array($pk)
				? '*'
				: sprintf('DISTINCT(%s)', $pk);
		}

		return (int) $this->__call('count', [$column]);
	}

	/**
	 * @return static<TEntity>
	 */
	public function limit(int $limit): self
	{
		$this->loader->getQuery()->limit($limit);

		return $this;
	}

	/**
	 * @return static<TEntity>
	 */
	public function offset(int $offset): self
	{
		$this->loader->getQuery()->offset($offset);

		return $this;
	}

	public function load(string|array $relation, array $options = []): self
	{
		if (is_string($relation)) {
			$this->loader->loadRelation($relation, $options, false, true);

			return $this;
		}

		foreach ($relation as $name => $subOption) {
			if (is_string($subOption)) {
				// array of relation names
				$this->load($subOption, $options);
			} else {
				// multiple relations or relation with addition load options
				$this->load($name, $subOption + $options);
			}
		}

		return $this;
	}

	public function with(string|array $relation, array $options = []): self
	{
		if (is_string($relation)) {
			$this->loader->loadRelation($relation, $options, true, false);

			return $this;
		}

		foreach ($relation as $name => $subOption) {
			if (is_string($subOption)) {
				//Array of relation names
				$this->with($subOption, []);
			} else {
				//Multiple relations or relation with addition load options
				$this->with($name, $subOption);
			}
		}

		return $this;
	}

	/**
	 * Load data tree from database and linked loaders in a form of array.
	 *
	 * @return array<array-key, array<string, mixed>>
	 */
	public function fetchData(bool $typecast = true): iterable
	{
		$node = $this->loader->createNode();
		$this->loader->loadData($node, false);

		if (! $typecast) {
			return $node->getResult();
		}

		$mapper = $this->factory->mapper($this->orm, $this->loader->getTarget());

		return array_map([$mapper, 'cast'], $node->getResult());
	}

	/**
	 * Compiled SQL statement.
	 */
	public function sqlStatement(): string
	{
		return $this->buildQuery()->sqlStatement();
	}

	public function loadSubclasses(bool $load = true): self
	{
		$this->loader->setSubclassesLoading($load);

		return $this;
	}

	/**
	 * @param list<non-empty-string> $pk
	 * @param list<array|int|object|string> $args
	 *
	 * @return self<TEntity>
	 */
	private function buildCompositePKQuery(array $pk, array $args): self
	{
		$prepared = [];
		foreach ($args as $index => $values) {
			$values = $values instanceof Parameter ? $values->getValue() : $values;
			if (! is_array($values)) {
				throw new InvalidArgumentException('Composite primary key must be defined using an array.');
			}
			if (count($pk) !== count($values)) {
				throw new InvalidArgumentException(
					sprintf('Primary key should contain %d values.', count($pk))
				);
			}

			$isAssoc = ! array_is_list($values);
			foreach ($values as $key => $value) {
				if ($isAssoc && ! \in_array($key, $pk, true)) {
					throw new InvalidArgumentException(sprintf('Primary key `%s` not found.', $key));
				}

				$key = $isAssoc ? $key : $pk[$key];
				$prepared[$index][$key] = $value;
			}
		}

		$this->__call('where', [static function (QueryBuilder $q) use ($prepared) {
			foreach ($prepared as $set) {
				$q->orWhere($set);
			}
		}]);

		return $this;
	}

	public function columns(array $columnsFilter): void
	{
		$columns = $this->loader->resolveColumns($this->collection, $columnsFilter);
		$this->loader->setColumns($columns);
	}
}
