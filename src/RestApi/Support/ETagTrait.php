<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Error\RestApiError;

use function ON\Mapper\map;

trait ETagTrait
{
	protected function getIfMatch(array $headers): ?string
	{
		return $headers['If-Match'] ?? $headers['if-match'] ?? null;
	}

	protected function checkIfMatch(CollectionInterface $collection, PrimaryKeyValue $identity, ?string $ifMatch): void
	{
		if ($ifMatch === null || $ifMatch === '') {
			return;
		}

		if ($ifMatch !== $this->getItemETag($collection, $identity)) {
			throw RestApiError::preconditionFailed();
		}
	}

	protected function getItemETag(CollectionInterface $collection, PrimaryKeyValue|string $identity): string
	{
		$current = $this->getItemForETag($collection, $identity);

		if ($current === null) {
			throw RestApiError::notFound();
		}

		return $this->computeETag(json_encode(
			map($current)
				->using(CollectionRowMapper::class, $collection)
				->from(PhpRepresentation::class)
				->as(WireRepresentation::class)
				->toArray(),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
		));
	}

	protected function computeETag(string $jsonBody): string
	{
		return 'W/"' . md5($jsonBody) . '"';
	}

	abstract protected function getItemForETag(CollectionInterface $collection, PrimaryKeyValue|string $identity): ?array;
}
