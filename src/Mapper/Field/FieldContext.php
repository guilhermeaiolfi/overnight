<?php

declare(strict_types=1);

namespace ON\Mapper\Field;

use ON\ORM\Definition\Field\FieldInterface;

final class FieldContext
{
	public function __construct(
		private readonly string $name,
		private readonly string $type,
		private readonly bool $nullable = false,
		private readonly ?FieldInterface $field = null,
	) {
	}

	public static function fromField(FieldInterface $field): self
	{
		return new self(
			$field->getName(),
			$field->getType(),
			method_exists($field, 'isNullable') ? $field->isNullable() : false,
			$field,
		);
	}

	/**
	 * @param class-string|non-empty-string $type
	 */
	public static function named(string $name, string $type, bool $nullable = false): self
	{
		return new self($name, $type, $nullable);
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function isNullable(): bool
	{
		return $this->nullable;
	}

	public function getField(): ?FieldInterface
	{
		return $this->field;
	}

	public function isClassType(): bool
	{
		return class_exists($this->type);
	}
}
