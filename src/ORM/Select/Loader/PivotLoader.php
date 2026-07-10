<?php

declare(strict_types=1);

namespace ON\ORM\Select\Loader;

use Cycle\Database\Query\SelectQuery;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
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
		return $this->target->getTable();
	}

	public function configureQuery(SelectQuery $query, array $outerKeys = []): SelectQuery
	{
		// user specified WHERE conditions
		$this->setWhere(
			$query,
			$this->isJoined() ? 'onWhere' : 'where',
			$this->relation->getThrough()->getWhere()
		);

		return parent::configureQuery($query, $outerKeys);
	}

	protected function initNode(): AbstractNode
	{
		return new ArrayNode(
			$this->columnNames(),
			$this->target->getPrimaryKey(),
			$this->relation->getThrough()->getOuterKeys(),
			$this->relation->getInnerKeys()
		);
	}
}
