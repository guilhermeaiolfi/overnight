<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Field;

use ON\CMS\Definition\Collection\CollectionInterface;
use ON\CMS\Definition\Display\DisplayTrait;
use ON\CMS\Definition\Exception\FieldException;
use ON\CMS\Definition\Interface\InterfaceTrait;

class Field implements FieldInterface
{
	use DisplayTrait;
	use InterfaceTrait;
	use SchemaTrait;

	protected string $name;

	protected ?string $column = null;

	protected ?string $type = null;

	protected bool $required = false;

	protected bool $sensible = false;

	/**
	 * @var callable-array|string|null
	 */
	private array|string|null $typecast = null;

	public function __construct(
		protected CollectionInterface $collection
	) {

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

	public function type(string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function getType(): string
	{
		if (empty($this->type)) {
			throw new FieldException('Field type must be set');
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
