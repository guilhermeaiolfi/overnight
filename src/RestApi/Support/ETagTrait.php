<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\RestApi\Error\RestApiError;

trait ETagTrait
{
	protected function getIfMatch(array $headers): ?string
	{
		return $headers['If-Match'] ?? $headers['if-match'] ?? null;
	}

	protected function checkIfMatch(CollectionInterface $collection, Key $identity, ?string $ifMatch): void
	{
		if ($ifMatch === null || $ifMatch === '') {
			return;
		}

		if ($ifMatch !== $this->getItemETag($collection, $identity)) {
			throw RestApiError::preconditionFailed();
		}
	}

	protected function getItemETag(CollectionInterface $collection, Key|string $identity): string
	{
		$current = $this->getItemForETag($collection, $identity);

		if ($current === null) {
			throw RestApiError::notFound();
		}

		return $this->computeETag(json_encode(
			map($current)
				->args($collection)
				->from(PhpRepresentation::class)
				->as(WireRepresentation::class)
				->to([]),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
		));
	}

	protected function computeETag(string $jsonBody): string
	{
		return 'W/"' . md5($jsonBody) . '"';
	}

	abstract protected function getItemForETag(CollectionInterface $collection, Key|string $identity): ?array;
}
