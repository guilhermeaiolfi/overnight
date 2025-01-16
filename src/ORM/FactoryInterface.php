<?php

declare(strict_types=1);

namespace ON\ORM;

use Cycle\Database\DatabaseProviderInterface;
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
		string $role,
		string $relation
	): LoaderInterface;

	/**
	 * Create source associated with given role.
	 */
	public function source(
		Registry $registry,
		string $collection
	): SourceInterface;
}
