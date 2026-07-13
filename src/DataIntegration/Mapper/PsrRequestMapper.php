<?php

declare(strict_types=1);

namespace ON\DataIntegration\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingBranch;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Support\ArrayPathExpander;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Adapts a PSR-7 ServerRequest into enumerated mapping children (query + parsed body).
 */
final class PsrRequestMapper implements MapperInterface
{
	public function __construct(
		private readonly ?ArrayPathExpander $pathExpander = null,
	) {
	}

	public static function canMap(
		mixed $source,
		MappingOptions $options,
	): bool {
		return $source instanceof ServerRequestInterface;
	}

	public function map(MappingBranch $context): mixed
	{
		$source = $context->getSource();
		if (! $source instanceof ServerRequestInterface) {
			throw new MappingException('PsrRequestMapper can only map ServerRequestInterface sources.');
		}

		$parsedBody = $source->getParsedBody();
		$payload = array_merge(
			$source->getQueryParams(),
			is_array($parsedBody) ? $parsedBody : [],
		);

		$payload = ($this->pathExpander ?? new ArrayPathExpander())->expand($payload);

		foreach ($payload as $name => $value) {
			$context->write(
				name: $name,
				value: $value,
			);
		}

		return $context->getResult();
	}
}
