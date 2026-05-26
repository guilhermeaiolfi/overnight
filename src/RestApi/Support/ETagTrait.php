<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Error\RestApiError;

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
			$this->serialize($collection, $current),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
		));
	}

	protected function computeETag(string $jsonBody): string
	{
		return 'W/"' . md5($jsonBody) . '"';
	}

	abstract protected function getItemForETag(CollectionInterface $collection, PrimaryKeyValue|string $identity): ?array;
}
