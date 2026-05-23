<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class ClassTypecast implements TypecastInterface
{
	/**
	 * @param class-string $class
	 */
	public function __construct(
		private readonly string $class
	) {
	}

	public function toPhp(mixed $storage, FieldInterface $field): mixed
	{
		if ($storage === null) {
			return null;
		}

		if ($storage instanceof $this->class) {
			return $storage;
		}

		if (method_exists($this->class, 'fromStorage')) {
			return $this->class::fromStorage($storage);
		}

		if (is_string($storage) && method_exists($this->class, 'fromString')) {
			return $this->class::fromString($storage);
		}

		return $storage;
	}

	public function fromPhp(mixed $php, FieldInterface $field): mixed
	{
		if ($php === null) {
			return null;
		}

		if ($php instanceof $this->class && method_exists($php, 'toStorage')) {
			return $php->toStorage();
		}

		if (is_object($php) && method_exists($php, '__toString')) {
			return (string) $php;
		}

		return $php;
	}
}
