<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Collection;

use Cycle\ORM\Mapper\StdMapper;
use ON\ORM\Definition\Field\Field;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Field\FieldMap;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\HasOneRelation;
use ON\ORM\Definition\Relation\RelationInterface;
use ON\ORM\Definition\Relation\RelationMap;
use ON\ORM\Select\Source;
use stdClass;

class Collection implements CollectionInterface
{
	public string $name;
	public ?string $note = null;
	public ?string $source = Source::class;
	public bool $hidden = false;
	public string $mapper = StdMapper::class;
	public string $database = "default";
	public ?string $parentCollection = null;
	public string $entity = stdClass::class;
	public ?string $table = null;
	public FieldMap $fields;
	public RelationMap $relations;
	public ?string $fileLocation = null;

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

	public function field(string $name): FieldInterface
	{
		$field = new Field($this);
		$this->fields->set($name, $field);
		if (isset($name)) {
			$field->name($name);
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
