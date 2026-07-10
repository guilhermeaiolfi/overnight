<?php

declare(strict_types=1);

namespace ON\ORM\Select\Loader;

use Cycle\Database\Query\SelectQuery;
use Cycle\ORM\Exception\LoaderException;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\RelationInterface;
use ON\ORM\FactoryInterface;
use ON\ORM\Select\JoinableLoader;
use ON\ORM\Select\Traits\JoinOneTableTrait;
use ON\ORM\Select\Traits\OrderByTrait;
use ON\ORM\Select\Traits\WhereTrait;

/**
 * @internal
 */
class HasManyLoader extends JoinableLoader
{
	use JoinOneTableTrait;
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
		'columns' => ['*'],
	];

	public function __construct(
		Registry $registry,
		FactoryInterface $factory,
		CollectionInterface $collection,
		RelationInterface $relation,
		array $options
	) {
		parent::__construct($registry, $factory, $collection, $relation, $options);
		$this->options['where'] = $relation->getWhere();
		$this->options['orderBy'] = $relation->getOrderBy();
	}

	public function configureQuery(SelectQuery $query, array $outerKeys = []): SelectQuery
	{
		if ($this->isLoaded() && $this->isJoined() && (int) $query->getLimit() !== 0) {
			throw new LoaderException('Unable to load data using join with limit on parent query');
		}

		if ($this->options['using'] !== null) {
			// use pre-defined query
			return parent::configureQuery($query, $outerKeys);
		}

		$this->configureParentQuery($query, $outerKeys);

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

		return parent::configureQuery($query);
	}

	protected function initNode(): AbstractNode
	{

		return new ArrayNode(
			$this->columnNames(),
			$this->target->getPrimaryKey(),
			$this->relation->getOuterKeys(),
			$this->relation->getInnerKeys()
		);
	}
}
