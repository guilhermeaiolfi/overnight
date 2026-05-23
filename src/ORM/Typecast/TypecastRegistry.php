<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class TypecastRegistry
{
	/** @var array<string, TypecastInterface> */
	private array $instances = [];

	private readonly PassthroughTypecast $passthrough;

	public function __construct()
	{
		$this->passthrough = new PassthroughTypecast();
	}

	public function resolve(FieldInterface $field): TypecastInterface
	{
		if ($field->hasTypecast()) {
			$typecast = $field->getTypecast();

			if (is_string($typecast)) {
				return $this->resolveRule($typecast);
			}

			if (is_array($typecast) && isset($typecast[0]) && is_string($typecast[0])) {
				return $this->resolveRule($typecast[0]);
			}
		}

		try {
			return $this->resolveRule($field->getType(), $field);
		} catch (TypecastException) {
			if ($field->getType() === 'string' && $field->isNullable()) {
				return $this->for(StringTypecast::class);
			}

			return $this->passthrough;
		}
	}

	public function resolveRule(string $rule, ?FieldInterface $field = null): TypecastInterface
	{
		if (class_exists($rule) && is_subclass_of($rule, TypecastInterface::class)) {
			return $this->for($rule);
		}

		return match ($rule) {
			'datetime', 'timestamp' => $this->for(DateTimeTypecast::class),
			'date' => $this->for(DateTypecast::class),
			'bool', 'boolean' => $this->for(BoolTypecast::class),
			'int', 'integer', 'primary', 'smallPrimary', 'bigPrimary' => $this->for(IntTypecast::class),
			'float', 'double', 'decimal' => $this->for(FloatTypecast::class),
			'json' => $this->for(JsonTypecast::class),
			'string', 'text' => $field !== null && $field->isNullable()
				? $this->for(StringTypecast::class)
				: $this->passthrough,
			default => throw new TypecastException("Unknown typecast rule '{$rule}'.", $field?->getName()),
		};
	}

	/**
	 * @param class-string<TypecastInterface> $class
	 */
	private function for(string $class): TypecastInterface
	{
		return $this->instances[$class] ??= new $class();
	}
}
