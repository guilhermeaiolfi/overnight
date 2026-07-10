<?php

declare(strict_types=1);

namespace ON\ORM\Select\Loader;

use function count;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\SelectQuery;
use Cycle\ORM\Exception\LoaderException;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\RelationInterface;
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
		CollectionInterface $collection,
		RelationInterface $relation,
		array $options = []
	) {
		parent::__construct($registry, $factory, $collection, $relation, $options);

		if (! $relation instanceof M2MRelation) {
			throw new LoaderException(sprintf(
				'ManyToManyLoader requires %s, %s given.',
				M2MRelation::class,
				$relation::class
			));
		}

		$this->pivot = new PivotLoader(
			$registry,
			$factory,
			$relation->getThrough()->getCollection(),
			$relation,
			[]
		);
		$this->options['where'] = $relation->getWhere();
		$this->options['orderBy'] = $relation->getOrderBy();
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
			$parentKeys = $this->relation->getInnerKeys();
			$throughOuterKeys = $this->pivot->relation->getThrough()->getOuterKeys();
			$parentPrefix = $this->parent->getAlias() . '.';
			$on = [];
			foreach ($this->pivot->relation->getThrough()->getInnerKeys() as $i => $key) {
				$field = $pivotPrefix . $this->pivot->fieldAlias($key);
				$on[$field] = $parentPrefix . $this->parent->fieldAlias($parentKeys[$i]);
			}

			$query->join(
				$this->getJoinMethod(),
				$this->pivot->getJoinTable()
			)->on($on);

			$on = [];
			foreach ($this->relation->getOuterKeys() as $i => $key) {
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

			$outerKeyList = $this->relation->getOuterKeys();
			$on = [];
			foreach ($this->pivot->relation->getThrough()->getOuterKeys() as $i => $key) {
				$field = $pivotPrefix . $this->pivot->fieldAlias($key);
				$on[$field] = $localPrefix . $this->fieldAlias($outerKeyList[$i]);
			}

			$query->join(
				$this->getJoinMethod(),
				$this->pivot->getJoinTable()
			)->on($on);

			$fields = [];
			foreach ($this->pivot->relation->getThrough()->getInnerKeys() as $key) {
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
			$this->options['where'] ?? $this->relation->getWhere()
		);

		// user specified ORDER_BY rules
		$this->setOrderBy(
			$query,
			$this->getAlias(),
			$this->options['orderBy'] ?? $this->relation->getOrderBy()
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
		return new SingularNode(
			$this->columnNames(),
			$this->target->getPrimaryKey(),
			$this->relation->getOuterKeys(),
			$this->relation->getThrough()->getOuterKeys()
		);
	}
}
