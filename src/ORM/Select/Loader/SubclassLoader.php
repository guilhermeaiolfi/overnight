<?php

declare(strict_types=1);

namespace ON\ORM\Select\Loader;

use Cycle\Database\Query\SelectQuery;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SubclassMergeNode;
use Cycle\ORM\Relation;
use Cycle\ORM\SchemaInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\FactoryInterface;
use ON\ORM\Select\JoinableLoader;
use ON\ORM\Select\LoaderInterface;
use ON\ORM\Select\Traits\JoinOneTableTrait;

/**
 * Load children data.
 *
 * @internal
 */
class SubclassLoader extends JoinableLoader
{
	use JoinOneTableTrait;

	/**
	 * Default set of relation options. Child implementation might defined their of default options.
	 */
	protected array $options = [
		'load' => true,
		'constrain' => true,
		'method' => self::LEFT_JOIN,
		'minify' => true,
		'as' => null,
		'using' => null,
	];

	public function __construct(
		Registry $registry,
		SchemaInterface $ormSchema,
		FactoryInterface $factory,
		string $role,
		string $target
	) {
		$schemaArray = [
			Relation::INNER_KEY => $ormSchema->define($target, SchemaInterface::PARENT_KEY)
				?? $ormSchema->define($role, SchemaInterface::PRIMARY_KEY),
			Relation::OUTER_KEY => $ormSchema->define($target, SchemaInterface::PRIMARY_KEY),
		];
		$this->options['as'] ??= $target;
		parent::__construct($registry, $ormSchema, $factory, $role, $target, $schemaArray);
	}

	public function configureQuery(SelectQuery $query, array $outerKeys = []): SelectQuery
	{
		if ($this->options['using'] !== null) {
			// use pre-defined query
			return parent::configureQuery($query, $outerKeys);
		}

		$this->configureParentQuery($query, $outerKeys);

		return parent::configureQuery($query);
	}

	protected function getJoinMethod(): string
	{
		return 'LEFT';
	}

	protected function initNode(): AbstractNode
	{
		return new SubclassMergeNode(
			$this->target,
			$this->columnNames(),
			(array)$this->define(SchemaInterface::PRIMARY_KEY),
			(array)$this->schema[Relation::OUTER_KEY],
			(array)$this->schema[Relation::INNER_KEY]
		);
	}

	protected function generateParentLoader(string $role): ?LoaderInterface
	{
		return null;
	}
}
