<?php

declare(strict_types=1);

namespace ON\RestApi\Query;

use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Structural\MapperInterface;
use ON\Mapper\Structural\MappingContext;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\Parser\QueryParserInterface;
use RuntimeException;

/**
 * Directus wire query params → normalized QuerySpec.
 *
 * parse → normalize
 *
 * @example
 * map($query)
 *     ->using(DirectusQueryBuilder::class, $collection)
 *     ->to(QuerySpec::class);
 */
final class DirectusQueryBuilder implements MapperInterface
{
	private QueryParserInterface $parser;

	public function __construct(
		private readonly QueryNormalizer $normalizer,
		?QueryParserInterface $parser = null,
		int $defaultLimit = 100,
		int $maxLimit = 1000,
	) {
		$this->parser = $parser ?? new DirectusQueryParser(defaultLimit: $defaultLimit, maxLimit: $maxLimit);
	}

	public function defaultRepresentations(): array
	{
		return [
			'from' => WireRepresentation::class,
			'as' => PhpRepresentation::class,
		];
	}

	public function canMap(mixed $from, mixed $to, MappingContext $context): bool
	{
		if ($context->mapperClass !== null && $context->mapperClass !== self::class) {
			return false;
		}

		if ($to !== QuerySpec::class) {
			return false;
		}

		if (! is_array($from) && ! $from instanceof QuerySpec) {
			return false;
		}

		return $this->resolveCollection($context) !== null;
	}

	public function map(mixed $from, mixed $to, MappingContext $context): QuerySpec
	{
		$collection = $this->resolveCollection($context);
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

		return $this->normalizer->normalize(
			$this->parser->parse($collection, is_array($input) ? $input : [])
		);
	}

	private function resolveCollection(MappingContext $context): ?CollectionInterface
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
