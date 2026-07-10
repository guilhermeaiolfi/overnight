<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Support\MutationInput;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Converts uploaded files in Directus payloads to stored PHP values before binding.
 */
final class FileUploadEventEmitter
{
	public function __construct(
		private readonly RestHookDispatcher $hooks,
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function processInput(CollectionInterface $collection, array $input): array
	{
		[$scalars, $relations] = MutationInput::splitNodeInput($collection, $input);
		$scalars = $this->processFields($collection, $scalars);

		foreach ($relations as $relationName => $raw) {
			$relation = $collection->relations->get((string) $relationName);
			$target = $relation->getCollection();
			$relations[$relationName] = $this->processRelationPayload($target, $raw);
		}

		return $scalars + $relations;
	}

	private function processRelationPayload(CollectionInterface $target, mixed $raw): mixed
	{
		if ($raw === null || (! is_array($raw) && ! $raw instanceof UploadedFileInterface)) {
			return $raw;
		}

		if ($raw instanceof UploadedFileInterface) {
			return $raw;
		}

		if (MutationInput::isAssociativeArray($raw) && $this->isDetailed($raw)) {
			foreach (['create', 'update'] as $op) {
				if (! isset($raw[$op]) || ! is_array($raw[$op])) {
					continue;
				}
				foreach ($raw[$op] as $index => $item) {
					if (is_array($item)) {
						$raw[$op][$index] = $this->processInput($target, $item);
					}
				}
			}

			return $raw;
		}

		if (MutationInput::isAssociativeArray($raw)) {
			return $this->processInput($target, $raw);
		}

		foreach ($raw as $index => $item) {
			if (is_array($item)) {
				$raw[$index] = $this->processInput($target, $item);
			}
		}

		return $raw;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function isDetailed(array $input): bool
	{
		foreach (['create', 'update', 'delete'] as $key) {
			if (array_key_exists($key, $input)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function processFields(CollectionInterface $collection, array $fields): array
	{
		foreach ($fields as $name => $value) {
			if (! $value instanceof UploadedFileInterface) {
				continue;
			}

			$fields[$name] = $this->emitFileUpload($collection, (string) $name, $value);
		}

		return $fields;
	}

	private function emitFileUpload(
		CollectionInterface $collection,
		string $fieldName,
		UploadedFileInterface $file
	): mixed {
		$event = new FileUpload($collection, $fieldName, $file);
		$this->hooks->dispatch($collection, 'file.upload', $event, false);

		if ($event->getStoredValue() !== null) {
			return $event->getStoredValue();
		}

		if ($event->getStoredPath() !== null) {
			return $event->getStoredPath();
		}

		throw RestApiError::fileHandlerMissing($fieldName);
	}
}
