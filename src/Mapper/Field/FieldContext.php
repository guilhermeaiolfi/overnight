<?php

declare(strict_types=1);

namespace ON\Mapper\Field;

use ON\Data\Definition\Field\FieldInterface;

/**
 * Typed identity of one field for value conversion (name, type, nullable).
 *
 * Output of field resolvers; input to ConversionGateway::to() / convertScalar().
 * Handlers use this to pick datetime/int/enum rules — not where the value lives in a tree.
 */
final class FieldContext
{
	public function __construct(
		private readonly string $name,
		private readonly string $type,
		private readonly bool $nullable = false,
		private readonly ?FieldInterface $field = null,
		/** @var array<string, mixed> */
		private readonly array $metadata = [],
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
	 * @param array<string, mixed> $metadata
	 */
	public static function named(string $name, string $type, bool $nullable = false, array $metadata = []): self
	{
		return new self($name, $type, $nullable, null, $metadata);
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

	public function metadata(string $key, mixed $default = null): mixed
	{
		if ($this->field !== null && method_exists($this->field, 'metadata')) {
			$value = $this->field->metadata($key);
			if ($value !== null) {
				return $value;
			}
		}

		return $this->metadata[$key] ?? $default;
	}

	public function isClassType(): bool
	{
		return class_exists($this->type);
	}
}
