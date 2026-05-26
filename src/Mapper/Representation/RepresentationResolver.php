<?php

declare(strict_types=1);

namespace ON\Mapper\Representation;

use InvalidArgumentException;

final class RepresentationResolver
{
	/**
	 * @param class-string<RepresentationInterface>|string|null $hint
	 * @return class-string<RepresentationInterface>|null
	 */
	public static function resolve(?string $hint): ?string
	{
		if ($hint === null) {
			return null;
		}

		if (! class_exists($hint) || ! is_subclass_of($hint, RepresentationInterface::class)) {
			throw new InvalidArgumentException(sprintf(
				'Representation hint must be a %s implementation, `%s` given.',
				RepresentationInterface::class,
				$hint,
			));
		}

		return $hint;
	}

	/**
	 * @param class-string $target
	 */
	public static function isRepresentationHint(string $target): bool
	{
		return class_exists($target) && is_subclass_of($target, RepresentationInterface::class);
	}
}
