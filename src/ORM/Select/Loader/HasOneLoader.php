<?php

declare(strict_types=1);

namespace ON\ORM\Select\Loader;

use Cycle\Database\Query\SelectQuery;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;
use Cycle\ORM\Relation;
use ON\ORM\Select\JoinableLoader;
use ON\ORM\Select\Traits\JoinOneTableTrait;
use ON\ORM\Select\Traits\WhereTrait;

/**
 * Dedicated to load HAS_ONE relations, by default loader will prefer to join data into query.
 * Loader support MORPH_KEY.
 *
 * Please note that OUTER and INNER keys defined from perspective of parent (reversed for our
 * purposes).
 *
 * @internal
 */
class HasOneLoader extends JoinableLoader
{
	use JoinOneTableTrait;
	use WhereTrait;

	/**
	 * Default set of relation options. Child implementation might defined their of default options.
	 */
	protected array $options = [
		'load' => false,
		'scope' => true,
		'method' => self::INLOAD,
		'minify' => true,
		'as' => null,
		'using' => null,
		'where' => null,
	];

	public function configureQuery(SelectQuery $query, array $outerKeys = []): SelectQuery
	{
		if ($this->options['using'] !== null) {
			// use pre-defined query
			return parent::configureQuery($query, $outerKeys);
		}

		$this->configureParentQuery($query, $outerKeys);

		// user specified WHERE conditions
		$this->setWhere(
			$query,
			$this->isJoined() ? 'onWhere' : 'where',
			$this->options['where'] ?? $this->schema[Relation::WHERE] ?? []
		);

		return parent::configureQuery($query);
	}

	protected function initNode(): AbstractNode
	{
		$collection = $this->registry->getCollection($this->target);

		return new SingularNode(
			$this->columnNames(),
			(array)$collection->getPrimaryKey(true),
			(array)$this->relation->getOuterKey(),
			(array)$this->relation->getInnerKey()
		);
	}
}
