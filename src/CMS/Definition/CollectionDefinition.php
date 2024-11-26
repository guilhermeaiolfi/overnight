    <?php

declare(strict_types=1);

namespace ON\CMS\Definition;

use Cycle\ORM\Mapper\StdMapper;
use ON\CMS\Definition\Relation\O2ORelation;
use ON\CMS\Definition\Relation\Relation;
use stdClass;

class CollectionDefinition
{
	public string $name;
	public bool $hidden = false;
	public string $mapper = StdMapper::class;
	public string $entity = stdClass::class;
	public array $fields = [];
	public array $relations = [];

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

	public function hidden(bool $hidden): self
	{
		$this->hidden = $hidden;

		return $this;
	}

	public function isHidden(): bool
	{
		return $this->hidden;
	}

	public function getRelations(): array
	{
		return $this->relations;
	}

	public function field(string $name = null): FieldDefinition
	{

		$field = new FieldDefinition($this);
		$this->fields[] = $field;
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
	public function relation(string $name, string $type = O2ORelation::class): Relation
	{
		$relation = new $type($this);
		$this->relations[] = $relation;
		$relation->name($name);

		return $relation;
	}

	/** @return FieldDefinition[]|FieldDefinition */
	public function getPrimaryKey(): mixed
	{
		$pk = [];
		foreach ($this->fields as $field) {
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
