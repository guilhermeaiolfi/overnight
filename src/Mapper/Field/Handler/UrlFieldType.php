<?php

declare(strict_types=1);

namespace ON\Mapper\Field\Handler;

use ON\Mapper\Exception\ConversionException;
use ON\Mapper\Exception\UnsupportedConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;

final class UrlFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'string';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		return match ($from) {
			PhpRepresentation::class, StorageRepresentation::class, WireRepresentation::class => self::normalize($value, $field),
			default => throw UnsupportedConversionException::forRepresentation($from),
		};
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		return match ($to) {
			PhpRepresentation::class, StorageRepresentation::class, WireRepresentation::class => self::normalize($value, $field),
			default => throw UnsupportedConversionException::forRepresentation($to),
		};
	}

	private static function normalize(mixed $value, FieldContext $field): ?string
	{
		if (! is_scalar($value) && ! $value instanceof \Stringable) {
			throw new ConversionException('URL value must be scalar.', $field->getName());
		}

		$url = trim(str_replace('\\', '/', (string) $value));
		if ($url === '') {
			return $field->isNullable() ? null : '';
		}

		if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
			$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
			if (! in_array($scheme, self::allowedSchemes($field), true)) {
				throw new ConversionException(sprintf('URL scheme "%s" is not allowed.', $scheme), $field->getName());
			}

			return $url;
		}

		if (str_starts_with($url, '//')) {
			throw new ConversionException('Protocol-relative URLs are not allowed.', $field->getName());
		}

		$mode = self::option($field, 'mode', 'relative');
		if ($mode === 'absolute') {
			throw new ConversionException('Absolute URL is required.', $field->getName());
		}

		return '/' . ltrim($url, '/');
	}

	/**
	 * @return array<int, string>
	 */
	private static function allowedSchemes(FieldContext $field): array
	{
		$schemes = self::option($field, 'allowedSchemes', ['http', 'https']);
		if (is_string($schemes)) {
			$schemes = array_map('trim', explode(',', $schemes));
		}

		if (! is_array($schemes) || $schemes === []) {
			return ['http', 'https'];
		}

		return array_values(array_filter(array_map(
			static fn (mixed $scheme): string => strtolower(trim((string) $scheme)),
			$schemes
		)));
	}

	private static function option(FieldContext $field, string $key, mixed $default = null): mixed
	{
		$options = $field->metadata('url::options');
		if (is_array($options) && array_key_exists($key, $options)) {
			return $options[$key];
		}

		return $default;
	}
}
