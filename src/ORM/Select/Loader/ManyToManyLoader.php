<?php

declare(strict_types=1);

namespace ON\ORM\Select\Loader;

use function count;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\SelectQuery;
use Cycle\ORM\Exception\LoaderException;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;
use Cycle\ORM\Relation;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\RelationInterface;
use ON\ORM\FactoryInterface;
use ON\ORM\Select\JoinableLoader;
use ON\ORM\Select\LoaderInterface;
use ON\ORM\Select\Traits\OrderByTrait;
use ON\ORM\Select\Traits\WhereTrait;

/**
 * @internal
 */
class ManyToManyLoader extends JoinableLoader
{
	use OrderByTrait;
	use WhereTrait;

	/**
	 * Default set of relation options. Child implementation might defined their of default options.
	 */
	protected array $options = [
		'load' => false,
		'scope' => true,
		'method' => self::POSTLOAD,
		'minify' => true,
		'as' => null,
		'using' => null,
		'where' => null,
		'orderBy' => null,
		'pivot' => null,
	];

	protected PivotLoader $pivot;

	public function __construct(
		Registry $registry,
		FactoryInterface $factory,
		string $name,
		RelationInterface $relation,
	) {
		parent::__construct($registry, $factory, $name, $relation);
		$this->pivot = new PivotLoader(
			$registry,
			$factory,
			'pivot',
			$relation
		);
		$this->options['where'] = $relation->getWhere() ?? [];
		$this->options['orderBy'] = $relation->getOrderBy() ?? [];
	}

	/**
	 * Make sure that pivot loader is always carried with parent relation.
	 */
	public function __clone()
	{
		parent::__clone();
		$this->pivot = clone $this->pivot;
	}

	public function withContext(LoaderInterface $parent, array $options = []): static
	{
		/** @var ManyToManyLoader $loader */
		$loader = parent::withContext($parent, $options);
		$loader->pivot = $loader->pivot->withContext(
			$loader,
			[
				'load' => $loader->isLoaded(),
				'method' => $options['method'] ?? self::JOIN,
			] + ($options['pivot'] ?? [])
		);

		return $loader;
	}

	public function loadRelation(
		string|LoaderInterface $relation,
		array $options,
		bool $join = false,
		bool $load = false,
	): LoaderInterface {
		if ($relation === '@' || $relation === '@.@') {
			unset($options['method']);
			if ($options !== []) {
				// re-configure
				$this->pivot = $this->pivot->withContext($this, $options);
			}

			return $this->pivot;
		}

		return parent::loadRelation($relation, $options, $join, $load);
	}

	public function configureQuery(SelectQuery $query, array $outerKeys = []): SelectQuery
	{
		if ($this->isLoaded() && $this->isJoined() && (int) $query->getLimit() !== 0) {
			throw new LoaderException('Unable to load data using join with limit on parent query');
		}

		if ($this->options['using'] !== null) {
			// use pre-defined query
			return parent::configureQuery($this->pivot->configureQuery($query), $outerKeys);
		}


		$localPrefix = $this->getAlias() . '.';
		$pivotPrefix = $this->pivot->getAlias() . '.';

		// Manually join pivoted table
		if ($this->isJoined()) {
			$parentKeys = (array)$this->relation->getInnerKey();
			$throughOuterKeys = (array)$this->pivot->relation->through->getOuterKey();
			$parentPrefix = $this->parent->getAlias() . '.';
			$on = [];
			foreach ((array)$this->pivot->relation->through->getInnerKey() as $i => $key) {
				$field = $pivotPrefix . $this->pivot->fieldAlias($key);
				$on[$field] = $parentPrefix . $this->parent->fieldAlias($parentKeys[$i]);
			}

			$query->join(
				$this->getJoinMethod(),
				$this->pivot->getJoinTable()
			)->on($on);

			$on = [];
			foreach ((array)$this->relation->getOuterKey() as $i => $key) {
				$field = $localPrefix . $this->fieldAlias($key);
				$on[$field] = $pivotPrefix . $this->pivot->fieldAlias($throughOuterKeys[$i]);
			}

			$query->join(
				$this->getJoinMethod(),
				$this->getJoinTable()
			)->on($on);
		} elseif ($outerKeys !== []) {
			// reset all the columns when query is isolated (we have to do it manually
			// since underlying loader believes it's loaded)
			$query->columns([]);

			$outerKeyList = (array)$this->relation->getOuterKey();
			$on = [];
			foreach ((array)$this->pivot->relation->through->getOuterKey() as $i => $key) {
				$field = $pivotPrefix . $this->pivot->fieldAlias($key);
				$on[$field] = $localPrefix . $this->fieldAlias($outerKeyList[$i]);
			}

			$query->join(
				$this->getJoinMethod(),
				$this->pivot->getJoinTable()
			)->on($on);

			$fields = [];
			foreach ((array)$this->pivot->relation->through->getInnerKey() as $key) {
				$fields[] = $pivotPrefix . $this->pivot->fieldAlias($key);
			}

			if (count($fields) === 1) {
				$query->andWhere($fields[0], 'IN', new Parameter(array_column($outerKeys, key($outerKeys[0]))));
			} else {
				$query->andWhere(
					static function (SelectQuery $select) use ($outerKeys, $fields) {
						foreach ($outerKeys as $set) {
							$select->orWhere(array_combine($fields, array_values($set)));
						}
					}
				);
			}
		}

		// user specified WHERE conditions
		$this->setWhere(
			$query,
			$this->isJoined() ? 'onWhere' : 'where',
			$this->options['where'] ?? $this->schema[Relation::WHERE] ?? []
		);

		// user specified ORDER_BY rules
		$this->setOrderBy(
			$query,
			$this->getAlias(),
			$this->options['orderBy'] ?? $this->schema[Relation::ORDER_BY] ?? []
		);

		return parent::configureQuery($this->pivot->configureQuery($query));
	}

	public function createNode(): AbstractNode
	{
		$node = $this->pivot->createNode();
		$node->joinNode('@', parent::createNode());

		return $node;
	}

	protected function loadChild(AbstractNode $node, bool $includeRole = false): void
	{
		$rootNode = $node->getNode('@');
		foreach ($this->load as $relation => $loader) {
			$loader->loadData($rootNode->getNode($relation), $includeRole);
		}

		$this->pivot->loadChild($node, $includeRole);
	}

	protected function mountColumns(
		SelectQuery $query,
		bool $minify = false,
		string $prefix = '',
		bool $overwrite = false
	): SelectQuery {
		// columns are reset on earlier stage to allow pivot loader mount it's own aliases
		return parent::mountColumns($query, $minify, $prefix, false);
	}

	protected function initNode(): AbstractNode
	{
		$collection = $this->registry->getCollection($this->target);

		return new SingularNode(
			$this->columnNames(),
			(array)$collection->getPrimaryKey(true),
			(array)$this->relation->getOuterKey(),
			(array)$this->relation->through->getOuterKey()
		);
	}
}
