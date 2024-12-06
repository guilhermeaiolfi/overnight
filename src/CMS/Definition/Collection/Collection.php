<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Collection;

use Cycle\ORM\Mapper\StdMapper;
use ON\CMS\Definition\Field\Field;
use ON\CMS\Definition\Field\FieldInterface;
use ON\CMS\Definition\Field\FieldMap;
use ON\CMS\Definition\Relation\O2ORelation;
use ON\CMS\Definition\Relation\RelationInterface;
use ON\CMS\Definition\Relation\RelationMap;
use stdClass;

class Collection implements CollectionInterface
{
	public string $name;
	public ?string $note = null;
	public bool $hidden = false;
	public string $mapper = StdMapper::class;
	public string $entity = stdClass::class;
	public FieldMap $fields;
	public RelationMap $relations;

	public function __construct()
	{
		$this->fields = new FieldMap();
		$this->relations = new RelationMap();
	}

	/**
	 * @var class-string<ScopeInterface>|null
	 */
	private ?string $scope = null;

	/**
	 * @var class-string<RepositoryInterface>|null
	 */
	private ?string $repository = null;

	public function entity(string $entity): self
	{
		$this->entity = $entity;

		return $this;
	}

	public function getEntity(): string
	{
		return $this->entity;
	}

	public function scope(string $scope): self
	{
		$this->scope = $scope;

		return $this;
	}

	public function getScope(): string
	{
		return $this->scope;
	}

	public function repository(string $repository): self
	{
		$this->repository = $repository;

		return $this;
	}

	public function getRepository(): string
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
	public function relation(string $name, string $type = O2ORelation::class): RelationInterface
	{
		$relation = new $type($this);
		$this->relations->set($name, $relation);
		$relation->name($name);

		return $relation;
	}

	/** @return FieldInterface[]|FieldInterface */
	public function getPrimaryKey(): mixed
	{
		$pk = [];
		foreach ($this->fields as $name => $field) {
			if ($field->isPrimaryKey()) {
				$pk[] = $field;
			}
		}
		if (count($pk) == 1) {
			return $pk[0];
		}

		return $pk;
	}
}
