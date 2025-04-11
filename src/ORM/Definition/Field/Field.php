<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Field;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Display\DisplayTrait;
use ON\ORM\Definition\Exception\FieldException;
use ON\ORM\Definition\Interface\InterfaceTrait;

class Field implements FieldInterface
{
	use DisplayTrait;
	use InterfaceTrait;
	use SchemaTrait;

	protected string $name;

	protected ?string $column = null;

	protected ?string $type = null;

	protected ?string $alias = null;

	protected bool $required = false;

	protected bool $sensible = false;

	protected ?string $generatedFromRelation = null;

	/**
	 * @var callable-array|string|null
	 */
	private array|string|null $typecast = null;

	public function __construct(
		protected CollectionInterface $collection
	) {

	}

	public function setGeneratedFromRelation(?string $relation_name): self
	{
		$this->generatedFromRelation = $relation_name;

		return $this;
	}

	public function getGeneratedFromRelation(): ?string
	{
		return $this->generatedFromRelation;
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

	public function alias(string $alias): self
	{
		$this->alias = $alias;

		return $this;
	}

	public function getAlias(): string
	{
		return $this->alias ?? $this->name;
	}

	public function type(string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function getType(): string
	{
		if (empty($this->type)) {

			throw new FieldException('Field(' . $this->getName() . ') type must be set in collection: ' . $this->collection->getName());
		}

		return $this->type;
	}

	public function sensible(bool $sensible): self
	{
		$this->sensible = $sensible;

		return $this;
	}

	public function getSensible(): bool
	{
		return $this->sensible;
	}

	public function column(string $column): self
	{
		$this->column = $column;

		return $this;
	}

	public function getColumn(): string
	{
		if (! isset($this->column)) {
			return $this->name;
		}

		return $this->column;
	}

	public function required(bool $required): self
	{
		$this->required = $required;

		return $this;
	}

	public function isRequired(): bool
	{
		return $this->required;
	}

	public function hasTypecast(): bool
	{
		return $this->typecast !== null;
	}

	/**
	 * @param callable-array|string|null $typecast
	 */
	public function typecast(array|string|null $typecast): self
	{
		$this->typecast = $typecast;

		return $this;
	}

	/**
	 * @return callable-array|string|null
	 */
	public function getTypecast(): array|string|null
	{
		return $this->typecast;
	}

	public function end(): CollectionInterface
	{
		return $this->collection;
	}
}
