<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

final class TypecastOptions
{
	public static function shouldTypecast(array $options): bool
	{
		return (bool) ($options['typecast'] ?? true);
	}

	public static function fromQuery(array $query): array
	{
		if (! array_key_exists('typecast', $query)) {
			return [];
		}

		$value = filter_var($query['typecast'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

		return ['typecast' => $value ?? true];
	}
}
