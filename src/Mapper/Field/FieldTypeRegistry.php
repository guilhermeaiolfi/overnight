<?php

declare(strict_types=1);

namespace ON\Mapper\Field;

use ON\Mapper\Field\Handler\BackedEnumFieldType;
use ON\Mapper\Field\Handler\BoolFieldType;
use ON\Mapper\Field\Handler\ClassFieldType;
use ON\Mapper\Field\Handler\DateFieldType;
use ON\Mapper\Field\Handler\DateTimeFieldType;
use ON\Mapper\Field\Handler\FloatFieldType;
use ON\Mapper\Field\Handler\IntFieldType;
use ON\Mapper\Field\Handler\JsonFieldType;
use ON\Mapper\Field\Handler\PassthroughFieldType;
use ON\Mapper\Field\Handler\StringFieldType;

final class FieldTypeRegistry
{
	/** @var array<class-string, class-string<FieldTypeInterface>> */
	private array $handlers = [];

	/** @var array<string, class-string<FieldTypeInterface>> */
	private array $builtins = [
		'datetime' => DateTimeFieldType::class,
		'timestamp' => DateTimeFieldType::class,
		'date' => DateFieldType::class,
		'bool' => BoolFieldType::class,
		'boolean' => BoolFieldType::class,
		'int' => IntFieldType::class,
		'integer' => IntFieldType::class,
		'primary' => IntFieldType::class,
		'smallprimary' => IntFieldType::class,
		'bigprimary' => IntFieldType::class,
		'float' => FloatFieldType::class,
		'double' => FloatFieldType::class,
		'decimal' => FloatFieldType::class,
		'json' => JsonFieldType::class,
		'string' => StringFieldType::class,
		'text' => PassthroughFieldType::class,
	];

	public function __construct()
	{
		foreach ($this->builtins as $type => $handler) {
			$this->handlers[$type] = $handler;
		}
	}

	/**
	 * @param class-string $type
	 * @param class-string<FieldTypeInterface> $handler
	 */
	public function register(string $type, string $handler): void
	{
		$this->handlers[$type] = $handler;
	}

	public function resolve(FieldContext $field): ?string
	{
		$type = $field->getType();

		if (is_subclass_of($type, FieldTypeInterface::class)) {
			return $type;
		}

		if (isset($this->handlers[$type])) {
			return $this->handlers[$type];
		}

		$lower = strtolower($type);
		if (isset($this->handlers[$lower])) {
			return $this->handlers[$lower];
		}

		if (class_exists($type) && is_subclass_of($type, \DateTimeInterface::class)) {
			return DateTimeFieldType::class;
		}

		if (enum_exists($type)) {
			return BackedEnumFieldType::class;
		}

		if (class_exists($type)) {
			return ClassFieldType::class;
		}

		return null;
	}

	/**
	 * @return class-string<FieldTypeInterface>|null
	 */
	public function resolveBuiltin(string $rule): ?string
	{
		$lower = strtolower($rule);

		return $this->handlers[$lower] ?? null;
	}
}
