<?php

declare(strict_types=1);

namespace Tests\ON\RestApi\Support;

use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class UploadPassthroughFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['upload'];
	}

	public static function getStorageType(): string
	{
		return 'int';
	}

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return $value;
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return $value;
	}
}
