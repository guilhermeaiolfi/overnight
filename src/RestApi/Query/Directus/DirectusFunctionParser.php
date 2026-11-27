<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Directus;

use ON\Data\Query\QueryFunction\Standard\Temporal\DateValue;
use ON\Data\Query\QueryFunction\Standard\Temporal\Day;
use ON\Data\Query\QueryFunction\Standard\Temporal\Hour;
use ON\Data\Query\QueryFunction\Standard\Temporal\Month;
use ON\Data\Query\QueryFunction\Standard\Temporal\Year;

final class DirectusFunctionParser
{
	/**
	 * @var array<string, class-string>
	 */
	public const FUNCTION_CLASSES = [
		'year' => Year::class,
		'month' => Month::class,
		'day' => Day::class,
		'hour' => Hour::class,
		'date' => DateValue::class,
	];

	public function parse(string $value): ParsedField
	{
		$value = trim($value);
		if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\(([a-zA-Z_][a-zA-Z0-9_]*)\)$/', $value, $matches)) {
			$function = strtolower($matches[1]);
			if (isset(self::FUNCTION_CLASSES[$function])) {
				return new ParsedField($matches[2], $function);
			}
		}

		return new ParsedField($value);
	}

	public function alias(string $value): string
	{
		return $this->parse($value)->alias($value);
	}
}
