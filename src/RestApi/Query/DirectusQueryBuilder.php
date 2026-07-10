<?php

declare(strict_types=1);

namespace ON\RestApi\Query;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Structural\MapperInterface;
use ON\Mapper\Structural\MappingContext;
use ON\RestApi\Query\Node\QuerySpec;
use RuntimeException;

/**
 * Legacy QuerySpec normalizer retained for mutation-era callers that still hold a QuerySpec.
 *
 * @deprecated Use DirectusQueryParser(DataRuntime) for reads.
 *
 * Read paths use DirectusQueryParser → SelectQuery directly.
 */
final class DirectusQueryBuilder implements MapperInterface
{
	public function __construct(
		private readonly QueryNormalizer $normalizer,
	) {
	}

	public static function defaultRepresentations(): array
	{
		return [
			'from' => WireRepresentation::class,
			'as' => PhpRepresentation::class,
		];
	}

	public static function canMap(mixed $from, mixed $to, MappingContext $context): bool
	{
		if ($context->mapperClass !== null && $context->mapperClass !== self::class) {
			return false;
		}

		if ($to !== QuerySpec::class) {
			return false;
		}

		if (! $from instanceof QuerySpec) {
			return false;
		}

		return self::resolveCollection($context) !== null;
	}

	public function map(mixed $from, mixed $to, MappingContext $context): QuerySpec
	{
		$collection = self::resolveCollection($context);
		if ($collection === null) {
			throw new RuntimeException('DirectusQueryBuilder requires a CollectionInterface argument.');
		}

		return $this->build($collection, $from);
	}

	public function build(CollectionInterface $collection, mixed $input = []): QuerySpec
	{
		if ($input instanceof QuerySpec) {
			return $this->normalizer->normalize($input);
		}

		throw new RuntimeException(
			'DirectusQueryBuilder no longer parses wire query params. Use DirectusQueryParser with DataRuntime.'
		);
	}

	private static function resolveCollection(MappingContext $context): ?CollectionInterface
	{
		if ($context->mapperClass === self::class && isset($context->args[0])) {
			$collection = $context->args[0];
			if ($collection instanceof CollectionInterface) {
				return $collection;
			}
		}

		return null;
	}
}
