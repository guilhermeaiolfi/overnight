<?php

declare(strict_types=1);

namespace ON\RestApi\Payload;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Support\MutationInput;
use Psr\Http\Message\UploadedFileInterface;

final class MutationInputPreparer
{
	public function __construct(
		private readonly MutationInputMerger $merger,
		private readonly RestHookDispatcher $hooks,
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @return array<string, mixed>
	 */
	public function prepare(CollectionInterface $collection, array $input, array $files = []): array
	{
		$merged = $this->merger->mergeFiles($collection, $input, $files);

		return $this->resolveUploads($collection, $merged);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function resolveUploads(CollectionInterface $collection, array $input): array
	{
		foreach ($input as $name => $value) {
			if (is_string($name) && $collection->fields->has($name) && $value instanceof UploadedFileInterface) {
				$input[$name] = $this->emitFileUpload($collection, $name, $value);
			}
		}

		[, $relations] = MutationInput::splitNodeInput($collection, $input);
		foreach ($relations as $relationName => $relationInput) {
			if (! $collection->relations->has((string) $relationName)) {
				continue;
			}

			$relation = $collection->relations->get((string) $relationName);
			$input[$relationName] = $this->resolveRelationInput($relation->getCollection(), $relationInput);
		}

		return $input;
	}

	private function resolveRelationInput(CollectionInterface $targetCollection, mixed $relationInput): mixed
	{
		if (! is_array($relationInput)) {
			return $relationInput;
		}

		if (MutationInput::isAssociativeArray($relationInput) && $this->hasOperationPayload($relationInput)) {
			foreach (['create', 'update'] as $operation) {
				foreach (MutationInput::normalizeRelationItems($relationInput[$operation] ?? []) as $index => $item) {
					if (! is_array($item)) {
						continue;
					}

					$relationInput[$operation][$index] = $this->resolveUploads($targetCollection, $item);
				}
			}

			return $relationInput;
		}

		if (MutationInput::isAssociativeArray($relationInput)) {
			return $this->resolveUploads($targetCollection, $relationInput);
		}

		foreach ($relationInput as $index => $item) {
			if (! is_array($item)) {
				continue;
			}

			$relationInput[$index] = $this->resolveUploads($targetCollection, $item);
		}

		return $relationInput;
	}

	private function hasOperationPayload(array $input): bool
	{
		foreach (['create', 'update', 'delete'] as $key) {
			if (array_key_exists($key, $input)) {
				return true;
			}
		}

		return false;
	}

	private function emitFileUpload(
		CollectionInterface $collection,
		string $fieldName,
		UploadedFileInterface $file,
	): mixed {
		$event = new FileUpload($collection, $fieldName, $file);
		$this->hooks->dispatch($event, false);

		if ($event->getStoredValue() !== null) {
			return $event->getStoredValue();
		}

		if ($event->getStoredPath() !== null) {
			return $event->getStoredPath();
		}

		throw RestApiError::fileHandlerMissing($fieldName);
	}
}
