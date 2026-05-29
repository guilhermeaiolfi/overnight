<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\ConversionGateway;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Support\ArrayHelper;
use Psr\Http\Message\ServerRequestInterface;

final class PsrRequestToObjectMapper implements MapperInterface
{
	public function __construct(
		private readonly ConversionGateway $gateway,
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

		return $from instanceof ServerRequestInterface && is_string($to) && class_exists($to);
	}

	public function map(mixed $from, mixed $to, MappingContext $context): mixed
	{
		/** @var ServerRequestInterface $from */
		$payload = ArrayHelper::undot(array_merge(
			$from->getQueryParams(),
			is_array($from->getParsedBody()) ? $from->getParsedBody() : [],
		));

		$sourceRepresentation = $context->sourceRepresentation
			?? self::defaultRepresentations()['from']
			?? WireRepresentation::class;

		return $this->gateway->getMappers()->map(
			$payload,
			$to,
			$context->withSourceRepresentation($sourceRepresentation),
		);
	}
}
