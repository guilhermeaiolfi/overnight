<?php

declare(strict_types=1);

namespace ON\Mapper\Exception;

final class UnsupportedConversionException extends ConversionException
{
	public static function forRepresentations(string $from, string $to): self
	{
		return new self("Conversion from `{$from}` to `{$to}` is not supported.");
	}

	public static function forRepresentation(string $representation): self
	{
		return new self("Conversion for representation `{$representation}` is not supported.");
	}

	public static function unregistered(string $representation): self
	{
		return new self("No representation registered for `{$representation}`.");
	}
}
