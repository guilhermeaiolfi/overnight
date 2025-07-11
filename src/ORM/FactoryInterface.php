<?php

declare(strict_types=1);

namespace ON\ORM;

use Cycle\Database\DatabaseProviderInterface;
use Cycle\ORM\MapperInterface;
use Cycle\ORM\ORMInterface;
use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Registry;
use ON\ORM\Select\LoaderInterface;
use ON\ORM\Select\SourceInterface;
use Spiral\Core\FactoryInterface as CoreFactory;

/**
 * Must provide access to generic DI.
 */
interface FactoryInterface extends DatabaseProviderInterface, CoreFactory
{
	public const PARENT_LOADER = '::parent::';
	public const CHILD_LOADER = '::child::';

	/**
	 * Create loader associated with specific entity and relation.
	 */
	public function loader(
		Registry $registry,
		Collection $collection,
		string $relation,
		array $options
	): LoaderInterface;

	/**
	 * Create source associated with given role.
	 */
	public function source(
		Registry $registry,
		Collection $collection
	): SourceInterface;

	public function mapper(
		ORMInterface $orm,
		Collection $collection
	): MapperInterface;
}
