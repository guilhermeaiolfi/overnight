<?php

declare(strict_types=1);

namespace ON\ORM;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseProviderInterface;
use Cycle\ORM\Collection\ArrayCollectionFactory;
use Cycle\ORM\Collection\CollectionFactoryInterface;
use Cycle\ORM\Config\RelationConfig;
use Cycle\ORM\Exception\TypecastException;
use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\MapperInterface;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\SchemaInterface;
use function is_object;
use function is_subclass_of;
use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Registry;
use ON\ORM\Select\Loader\ParentLoader;
use ON\ORM\Select\Loader\SubclassLoader;
use ON\ORM\Select\LoaderInterface;
use ON\ORM\Select\Repository;
use ON\ORM\Select\ScopeInterface;
use ON\ORM\Select\Source;
use ON\ORM\Select\SourceInterface;
use Spiral\Core\Container;
use Spiral\Core\FactoryInterface as CoreFactory;

final class Factory implements FactoryInterface
{
	private RelationConfig $config;
	private CoreFactory $factory;

	/** @var array<int, string> */
	private array $defaults = [
		SchemaInterface::REPOSITORY => Repository::class,
		SchemaInterface::SOURCE => Source::class,
		SchemaInterface::MAPPER => Mapper::class,
		SchemaInterface::SCOPE => null,
		SchemaInterface::TYPECAST_HANDLER => null,
	];

	/** @var array<string, CollectionFactoryInterface> */
	private array $collectionFactoryAlias = [];

	private array $mappers = [];
	/**
	 * @var array<string, CollectionFactoryInterface>
	 *
	 * @psalm-var array<class-string, CollectionFactoryInterface>
	 */
	private array $collectionFactoryInterface = [];

	private CollectionFactoryInterface $defaultCollectionFactory;

	public function __construct(
		private DatabaseProviderInterface $dbal,
		?RelationConfig $config = null,
		?CoreFactory $factory = null,
		?CollectionFactoryInterface $defaultCollectionFactory = null
	) {
		$this->config = $config ?? RelationConfig::getDefault();
		$this->factory = $factory ?? new Container();
		$this->defaultCollectionFactory = $defaultCollectionFactory ?? new ArrayCollectionFactory();
	}

	public function make(
		string $alias,
		array $parameters = []
	): mixed {
		return $this->factory->make($alias, $parameters);
	}

	public function mapper(ORMInterface $orm, Collection $collection): MapperInterface
	{
		$schema = $orm->getSchema();
		$class = $collection->getMapper();
		$role = $collection->getName();

		if (! is_subclass_of($class, MapperInterface::class)) {
			throw new TypecastException(\sprintf('%s does not implement %s.', $class, MapperInterface::class));
		}

		if (! isset($this->mappers[$role])) {
			$this->mappers[$role] = $this->factory->make(
				$class,
				[
					'orm' => $orm,
					'role' => $role,
					'schema' => $schema->define($role, SchemaInterface::SCHEMA),
				]
			);
		}

		return $this->mappers[$role];
	}

	public function loader(
		Registry $registry,
		Collection $collection,
		string $relation,
		array $options
	): LoaderInterface {

		//echo $collection_name . ":" . $relation . " | ";
		//$collection = $registry->getCollection($collection_name);
		/*if ($relation === self::PARENT_LOADER) {
			$parent = $collection->getParentCollection();

			return new ParentLoader($registry, $schema, $this, $role, $parent);
		}
		if ($relation === self::CHILD_LOADER) {
			$parent = $collection->getParentCollection();

			return new SubclassLoader($registry, $schema, $this, $parent, $role);
		}*/
		$relation = $collection->relations->get($relation);

		/*[
			'ormSchema' => $schema,
			'sourceProvider' => $sourceProvider,
			'factory' => $this,
			'role' => $role,
			'name' => $relation,
			'target' => $definition[Relation::TARGET],
			'schema' => $definition[Relation::SCHEMA],
		]*/
		$loader = $relation->getLoader();

		return new $loader($registry, $this, $registry->getCollection($relation->getCollection()), $relation, $options);
	}

	public function database(?string $database = null): DatabaseInterface
	{
		return $this->dbal->database($database);
	}

	public function source(
		Registry $registry,
		Collection $collection
	): SourceInterface {

		/** @var class-string<SourceInterface> $source */
		$source = $collection->getSource();

		if (! is_subclass_of($source, SourceInterface::class)) {
			throw new TypecastException($source . ' does not implement ' . SourceInterface::class);
		}

		$table = $collection->getName();
		$database = $this->database($collection->getDatabase());

		$source = new $source($database, $table);

		/** @var class-string<ScopeInterface>|ScopeInterface|null $scope */
		$scope = $collection->getScope();

		if ($scope === null) {
			return $source;
		}
		if (! is_subclass_of($scope, ScopeInterface::class)) {
			throw new TypecastException(sprintf('%s does not implement %s.', $scope, ScopeInterface::class));
		}

		return $source->withScope(is_object($scope) ? $scope : $this->factory->make($scope));
	}
}
