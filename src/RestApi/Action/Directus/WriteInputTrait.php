<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Error\RestApiError;

trait WriteInputTrait
{
	private function prepareInput(CollectionInterface $collection, array $typedInput, array $validationInput, bool $partial = false): array
	{
		$this->validate($collection, $validationInput, $partial);

		return $this->fileUploadEventEmitter->processInput($collection, $typedInput);
	}

	private function assertBatchSize(array $items): void
	{
		if (count($items) > max(1, (int) $this->config->get('maxBatchSize', 1000))) {
			throw new RestApiError('Too many items in a single batch.', 'BATCH_LIMIT_EXCEEDED', null, 400);
		}
	}

	private function assertObjectBatch(array $items, string $message): void
	{
		if (! array_is_list($items) || $items === []) {
			throw new RestApiError($message, 'INVALID_PAYLOAD', null, 400);
		}

		$this->assertBatchSize($items);
		foreach ($items as $item) {
			if (! is_array($item) || ($item !== [] && array_is_list($item))) {
				throw new RestApiError($message, 'INVALID_PAYLOAD', null, 400);
			}
		}
	}
}
