<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Field;

use ON\ORM\Definition\Relation\RelationInterface;

trait SchemaTrait
{
	public bool $nullable = false;

	public bool $hidden = false;

	public bool $unique = false;

	public bool $indexed = false;

	public int $max_length = 255;

	public int $numeric_precision = 2;

	public mixed $default_value = null;

	public ?string $data_type = null;

	public ?string $comment = null;

	protected bool $pk = false;

	protected bool $auto_increment = false;

	protected bool $filterable = true;

	public function numericPrecision(int $numeric_precision): self
	{
		$this->numeric_precision = $numeric_precision;

		return $this;
	}

	public function getNumericPrecision(): int
	{
		return $this->numeric_precision;
	}

	public function autoIncrement(bool $auto_increment): self
	{
		$this->auto_increment = $auto_increment;

		return $this;
	}

	public function isAutoIncrement(): bool
	{
		return $this->auto_increment;
	}

	public function primaryKey(bool $pk): self
	{
		$this->pk = $pk;
		if ($pk) {
			$this->filterable = false;
		}

		return $this;
	}

	public function isPrimaryKey(): bool
	{
		return $this->pk;
	}

	public function filterable(bool $filterable = true): self
	{
		$this->filterable = $filterable;

		return $this;
	}

	public function isFilterable(): bool
	{
		return $this->filterable;
	}

	/** @param RelationInterface|FieldInterface $parent */
	public function __construct(
		protected mixed $parent
	) {

	}

	public function dataType(mixed $data_type): self
	{
		$this->data_type = $data_type ;

		return $this;
	}

	public function getDataType(): mixed
	{
		return $this->data_type;
	}

	public function defaultValue(mixed $default_value): self
	{
		$this->default_value = $default_value ;

		return $this;
	}

	public function getDefaultValue(): mixed
	{
		return $this->default_value;
	}

	public function maxLength(int $max_length): self
	{
		$this->max_length = $max_length ;

		return $this;
	}

	public function getMaxLength(): int
	{
		return $this->max_length;
	}

	public function nullable(bool $nullable): self
	{
		$this->nullable = $nullable;

		return $this;
	}

	public function isNullable(): bool
	{
		return $this->nullable;
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

	public function unique(bool $unique): self
	{
		$this->unique = $unique;

		return $this;
	}

	public function isUnique(): bool
	{
		return $this->unique;
	}

	public function indexed(bool $indexed): self
	{
		$this->indexed = $indexed;

		return $this;
	}

	public function isIndexed(): bool
	{
		return $this->indexed;
	}

	public function comment(string $comment): self
	{
		$this->comment = $comment;

		return $this;
	}

	public function getComment(): ?string
	{
		return $this->comment;
	}

	public function end(): FieldInterface|RelationInterface
	{
		return $this->parent;
	}
}
