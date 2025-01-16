<?php

declare(strict_types=1);

namespace ON\ORM\Select\Loader;

use Cycle\Database\Query\SelectQuery;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
use Cycle\ORM\Relation;
use ON\ORM\Select\JoinableLoader;
use ON\ORM\Select\Traits\WhereTrait;

/**
 * Loads given entity table without any specific condition.
 *
 * @internal
 */
class PivotLoader extends JoinableLoader
{
	use WhereTrait;

	/**
	 * Default set of relation options. Child implementation might defined their of default options.
	 */
	protected array $options = [
		'load' => false,
		'scope' => true,
		'method' => self::JOIN,
		'minify' => true,
		'as' => null,
		'using' => null,
	];

	public function getTable(): string
	{
		return $this->target;
	}

	public function configureQuery(SelectQuery $query, array $outerKeys = []): SelectQuery
	{
		// user specified WHERE conditions
		$this->setWhere(
			$query,
			$this->isJoined() ? 'onWhere' : 'where',
			$this->relation->through->getWhere()
		);

		return parent::configureQuery($query, $outerKeys);
	}

	protected function initNode(): AbstractNode
	{
		$collection = $this->registry->getCollection($this->target);

		return new ArrayNode(
			$this->columnNames(),
			(array)$collection->getPrimaryKey(true),
			(array)$this->relation->through->getOuterKey(),
			(array)$this->relation->getInnerKey()
		);
	}
}
