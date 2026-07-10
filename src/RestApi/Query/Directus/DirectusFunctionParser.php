<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Directus;

final class DirectusFunctionParser
{
	private const FUNCTIONS = ['year', 'month', 'day', 'hour', 'date'];

	public function parse(string $value): ParsedField
	{
		$value = trim($value);
		if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\(([a-zA-Z_][a-zA-Z0-9_]*)\)$/', $value, $matches)) {
			$function = strtolower($matches[1]);
			if (in_array($function, self::FUNCTIONS, true)) {
				return new ParsedField($matches[2], $function);
			}
		}

		return new ParsedField($value);
	}

	public function alias(string $value): string
	{
		$parsed = $this->parse($value);

		return preg_replace(
			'/[^a-zA-Z0-9_]/',
			'_',
			$parsed->isFunction() ? $parsed->function . '_' . $parsed->field : $value,
		);
	}
}
