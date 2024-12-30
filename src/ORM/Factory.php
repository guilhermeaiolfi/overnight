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
use Cycle\ORM\Relation;
use Cycle\ORM\SchemaInterface;
use function is_object;
use function is_subclass_of;
use ON\ORM\Definition\Registry;
use ON\ORM\Select\Loader\BelongsToLoader;
use ON\ORM\Select\Loader\EmbeddedLoader;
use ON\ORM\Select\Loader\HasManyLoader;
use ON\ORM\Select\Loader\HasOneLoader;
use ON\ORM\Select\Loader\ManyToManyLoader;
use ON\ORM\Select\Loader\Morphed\MorphedHasManyLoader;
use ON\ORM\Select\Loader\Morphed\MorphedHasOneLoader;
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

	/**
	 * @var array<string, CollectionFactoryInterface>
	 *
	 * @psalm-var array<class-string, CollectionFactoryInterface>
	 */
	private array $collectionFactoryInterface = [];

	private CollectionFactoryInterface $defaultCollectionFactory;

	public function __construct(
		private DatabaseProviderInterface $dbal,
		RelationConfig $config = null,
		CoreFactory $factory = null,
		CollectionFactoryInterface $defaultCollectionFactory = null
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

	public function loader(
		Registry $registry,
		SchemaInterface $schema,
		string $role,
		string $relation
	): LoaderInterface {
		if ($relation === self::PARENT_LOADER) {
			$parent = $schema->define($role, SchemaInterface::PARENT);

			return new ParentLoader($registry, $schema, $this, $role, $parent);
		}
		if ($relation === self::CHILD_LOADER) {
			$parent = $schema->define($role, SchemaInterface::PARENT);

			return new SubclassLoader($registry, $schema, $this, $parent, $role);
		}
		$definition = $schema->defineRelation($role, $relation);


		switch ($definition[Relation::TYPE]) {

			case Relation::EMBEDDED:
				return new EmbeddedLoader($registry, $schema, $relation, $definition[Relation::TARGET], $definition[Relation::SCHEMA]);

				break;

			case Relation::HAS_ONE:
				return new HasOneLoader($registry, $schema, $this, $relation, $definition[Relation::TARGET], $definition[Relation::SCHEMA]);

				break;
			case Relation::BELONGS_TO:
				return new BelongsToLoader($registry, $schema, $this, $relation, $definition[Relation::TARGET], $definition[Relation::SCHEMA]);

				break;
			case Relation::REFERS_TO:
				return new BelongsToLoader($registry, $schema, $this, $relation, $definition[Relation::TARGET], $definition[Relation::SCHEMA]);

				break;
			case Relation::HAS_MANY:
				return new HasManyLoader($registry, $schema, $this, $relation, $definition[Relation::TARGET], $definition[Relation::SCHEMA]);

				break;
			case Relation::MANY_TO_MANY:
				return new ManyToManyLoader($registry, $schema, $this, $relation, $definition[Relation::TARGET], $definition[Relation::SCHEMA]);

				break;
			case Relation::MORPHED_HAS_ONE:
				return new MorphedHasOneLoader($registry, $schema, $this, $relation, $definition[Relation::TARGET], $definition[Relation::SCHEMA]);

				break;
			case Relation::MORPHED_HAS_MANY:
				return new MorphedHasManyLoader($registry, $schema, $this, $relation, $definition[Relation::TARGET], $definition[Relation::SCHEMA]);

				break;
		}
	}

	public function database(string $database = null): DatabaseInterface
	{
		return $this->dbal->database($database);
	}

	public function source(
		SchemaInterface $schema,
		string $role
	): SourceInterface {
		/** @var class-string<SourceInterface> $source */
		$source = $schema->define($role, SchemaInterface::SOURCE) ?? $this->defaults[SchemaInterface::SOURCE];

		if (! is_subclass_of($source, SourceInterface::class)) {
			throw new TypecastException($source . ' does not implement ' . SourceInterface::class);
		}

		$table = $schema->define($role, SchemaInterface::TABLE);
		$database = $this->database($schema->define($role, SchemaInterface::DATABASE));

		$source = $source !== Source::class
			? $this->factory->make($source, ['role' => $role, 'table' => $table, 'database' => $database])
			: new Source($database, $table);

		/** @var class-string<ScopeInterface>|ScopeInterface|null $scope */
		$scope = $schema->define($role, SchemaInterface::SCOPE) ?? $this->defaults[SchemaInterface::SCOPE];

		if ($scope === null) {
			return $source;
		}
		if (! is_subclass_of($scope, ScopeInterface::class)) {
			throw new TypecastException(sprintf('%s does not implement %s.', $scope, ScopeInterface::class));
		}

		return $source->withScope(is_object($scope) ? $scope : $this->factory->make($scope));
	}
}
