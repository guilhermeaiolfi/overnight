<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Collection;

use Cycle\ORM\Mapper\StdMapper;
use ON\ORM\Definition\Field\Field;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Field\FieldMap;
use ON\ORM\Definition\MetadataTrait;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\BelongsToRelation;
use ON\ORM\Definition\Relation\HasManyRelation;
use ON\ORM\Definition\Relation\HasOneRelation;
use ON\ORM\Definition\Relation\RelationInterface;
use ON\ORM\Definition\Relation\RelationMap;
use ON\ORM\Select\Source;
use stdClass;

class Collection implements CollectionInterface
{
	use MetadataTrait;
	protected string $name;
	protected ?string $note = null;
	protected ?string $source = Source::class;
	protected bool $hidden = false;
	protected string $mapper = StdMapper::class;
	protected string $database = "default";
	protected ?string $parentCollection = null;
	protected string $entity = stdClass::class;
	protected ?string $table = null;
	public FieldMap $fields;
	public RelationMap $relations;
	protected ?string $fileLocation = null;

	public function __construct(
		protected Registry $registry
	) {
		$this->fields = new FieldMap();
		$this->relations = new RelationMap();
	}

	/**
	 * @var class-string<ScopeInterface>|null
	 */
	protected ?string $scope = null;

	/**
	 * @var class-string<RepositoryInterface>|null
	 */
	private ?string $repository = null;

	public function table(string $table): self
	{
		$this->table = $table;

		return $this;
	}

	public function getTable(): string
	{
		return $this->table;
	}

	public function entity(string $entity): self
	{
		$this->entity = $entity;

		return $this;
	}

	public function getEntity(): string
	{
		return $this->entity;
	}

	public function database(string $database): self
	{
		$this->database = $database;

		return $this;
	}

	public function getDatabase(): string
	{
		return $this->database;
	}

	public function parentCollection(string $parentCollection): self
	{
		$this->parentCollection = $parentCollection;

		return $this;
	}

	public function getParentCollection(): ?string
	{
		return $this->parentCollection;
	}

	public function scope(string $scope): self
	{
		$this->scope = $scope;

		return $this;
	}

	public function getScope(): ?string
	{
		return $this->scope;
	}

	public function repository(?string $repository): self
	{
		$this->repository = $repository;

		return $this;
	}

	public function getRepository(): ?string
	{
		return $this->repository;
	}

	public function mapper(string $mapper): self
	{
		$this->mapper = $mapper;

		return $this;
	}

	public function getMapper(): string
	{
		return $this->mapper;
	}

	public function name(string $name): self
	{
		$this->name = $name;

		return $this;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function note(string $note): self
	{
		$this->note = $note;

		return $this;
	}

	public function getNote(): ?string
	{
		return $this->note;
	}

	public function source(string $source): self
	{
		$this->source = $source;

		return $this;
	}

	public function getSource(): ?string
	{
		return $this->source;
	}

	public function hidden(bool $hidden): self
	{
		$this->hidden = $hidden;

		return $this;
	}

	public function isHidden(): bool
	{
		return $this->hidden;
	}

	public function field(string $name, ?string $type = null): FieldInterface
	{
		$field = null;

		// it could get in here when generating fields because of relations
		// but that field could also be a primary field that's already defined
		if ($this->fields->has($name)) {
			$field = $this->fields->get($name);
		} else {
			$field = new Field($this);
			$this->fields->set($name, $field);
			if (isset($name)) {
				$field->name($name);
			}
			if (isset($type)) {
				$field->type($type);
			}
		}

		return $field;
	}

	/**
	 * @template T
	 * @param class-string<T> $type
	 * @return T
	 * */
	public function relation(string $name, string $type = HasOneRelation::class): RelationInterface
	{
		$relation = new $type($this);
		$this->relations->set($name, $relation);
		$relation->name($name);

		return $relation;
	}

	public function hasMany(string $name, string $targetCollection): HasManyRelation
	{
		/** @var HasManyRelation $relation */
		$relation = $this->relation($name, HasManyRelation::class);
		$relation->collection($targetCollection);
		return $relation;
	}

	public function hasOne(string $name, string $targetCollection): HasOneRelation
	{
		/** @var HasOneRelation $relation */
		$relation = $this->relation($name, HasOneRelation::class);
		$relation->collection($targetCollection);
		return $relation;
	}

	public function belongsTo(string $name, string $targetCollection): BelongsToRelation
	{
		/** @var BelongsToRelation $relation */
		$relation = $this->relation($name, BelongsToRelation::class);
		$relation->collection($targetCollection);
		return $relation;
	}

	/** @return FieldInterface[]|FieldInterface */
	public function getPrimaryKey(bool $parse = false): mixed
	{
		$pk = [];
		foreach ($this->fields as $name => $field) {
			if ($field->isPrimaryKey()) {
				$pk[] = $parse ? $field->getName() : $field;
			}
		}
		if (count($pk) == 1) {
			return $pk[0];
		}

		return $pk;
	}

	public function end(): Registry
	{
		return $this->registry;
	}

	public function getRegistry(): Registry
	{
		return $this->registry;
	}

	public function setFileDefinitionLocation(?string $file = null): void
	{
		$this->fileLocation = $file;
	}

	public function getFileDefinitionLocation(): ?string
	{
		return $this->fileLocation;
	}
}
