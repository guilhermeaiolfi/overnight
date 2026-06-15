<?php

declare(strict_types=1);

namespace ON\RestApi\Payload;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Support\MutationInput;
use Psr\Http\Message\UploadedFileInterface;

final class MutationInputMerger
{
	/**
	 * Merge uploaded files into mutation input so the parser can include them as field values.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @return array<string, mixed>
	 */
	public function mergeFiles(CollectionInterface $collection, array $input, array $files): array
	{
		if ($files === []) {
			return $input;
		}

		$files = $this->expandBracketedFileKeys($files);
		$input = $this->mergeCollectionFiles($collection, $input, $files);
		[, $relations] = MutationInput::splitNodeInput($collection, $input);

		foreach ($relations as $relationName => $relationInput) {
			$relation = $collection->relations->get((string) $relationName);
			$targetCollection = $relation->getCollection();
			$relationFiles = is_array($files[$relationName] ?? null) ? $files[$relationName] : [];

			if ($relation->isJunction()) {
				if (! is_array($relationInput) || ! MutationInput::isAssociativeArray($relationInput)) {
					continue;
				}

				foreach (MutationInput::normalizeRelationItems($relationInput['create'] ?? []) as $index => $item) {
					if (! is_array($item)) {
						continue;
					}

					$input[$relationName]['create'][$index] = $this->mergeFiles(
						$targetCollection,
						$item,
						is_array($relationFiles['create'][$index] ?? null) ? $relationFiles['create'][$index] : []
					);
				}

				continue;
			}

			if (is_array($relationInput) && MutationInput::isAssociativeArray($relationInput) && $this->hasOperationPayload($relationInput)) {
				foreach (['create', 'update'] as $operation) {
					foreach (MutationInput::normalizeRelationItems($relationInput[$operation] ?? []) as $index => $item) {
						if (! is_array($item)) {
							continue;
						}

						$operationFiles = is_array($relationFiles[$operation][$index] ?? null)
							? $relationFiles[$operation][$index]
							: (is_array($relationFiles[$index] ?? null) ? $relationFiles[$index] : []);

						$input[$relationName][$operation][$index] = $this->mergeFiles(
							$targetCollection,
							$item,
							$operationFiles,
						);
					}
				}

				continue;
			}

			if (is_array($relationInput) && MutationInput::isAssociativeArray($relationInput)) {
				$input[$relationName] = $this->mergeFiles($targetCollection, $relationInput, $relationFiles);
				continue;
			}

			$normalizedItems = MutationInput::normalizeRelationItems($relationInput);
			foreach ($normalizedItems as $index => $item) {
				if (! is_array($item)) {
					continue;
				}

				$input[$relationName][$index] = $this->mergeFiles(
					$targetCollection,
					$item,
					$this->resolveRelationItemFiles($relationFiles, $normalizedItems, $index, $item),
				);
			}
		}

		return $input;
	}

	/**
	 * @param array<string, mixed> $relationFiles
	 * @param list<mixed> $relationItems
	 * @return array<string, mixed>
	 */
	private function resolveRelationItemFiles(
		array $relationFiles,
		array $relationItems,
		int $index,
		array $item,
	): array {
		if (is_array($relationFiles[$index] ?? null)) {
			return $relationFiles[$index];
		}

		$indexedFiles = $this->extractFilesForRelationIndex($relationFiles, $index);
		if ($indexedFiles !== []) {
			return $indexedFiles;
		}

		if ($this->hasRelationId($item)) {
			return is_array($relationFiles['update'][$index] ?? null)
				? $relationFiles['update'][$index]
				: [];
		}

		$createIndex = 0;
		foreach ($relationItems as $scanIndex => $scanItem) {
			if ($scanIndex === $index) {
				break;
			}

			if (is_array($scanItem) && ! $this->hasRelationId($scanItem)) {
				$createIndex++;
			}
		}

		if (is_array($relationFiles['create'][$createIndex] ?? null)) {
			return $relationFiles['create'][$createIndex];
		}

		return [];
	}

	/**
	 * @param array<string, mixed> $relationFiles
	 * @return array<string, mixed>
	 */
	private function extractFilesForRelationIndex(array $relationFiles, int $index): array
	{
		$files = [];
		foreach ($relationFiles as $fieldName => $value) {
			if (is_array($value) && ($value[$index] ?? null) instanceof UploadedFileInterface) {
				$files[(string) $fieldName] = $value[$index];
			}
		}

		return $files;
	}

	private function hasRelationId(array $item): bool
	{
		return isset($item['id']) && is_numeric($item['id']) && (int) $item['id'] > 0;
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

	/**
	 * @param array<string, mixed> $files
	 * @return array<string, mixed>
	 */
	private function expandBracketedFileKeys(array $files): array
	{
		$expanded = [];

		foreach ($files as $name => $value) {
			$name = str_replace(['%5B', '%5D'], ['[', ']'], (string) $name);
			$value = is_array($value) ? $this->expandBracketedFileKeys($value) : $value;

			if (! str_contains($name, '[')) {
				$expanded[$name] = $value;
				continue;
			}

			preg_match_all('/[^\[\]]+/', $name, $matches);
			$keys = $matches[0] ?? [];
			if ($keys === []) {
				continue;
			}

			$this->setNestedFileValue($expanded, $keys, $value);
		}

		return $expanded;
	}

	/**
	 * @param array<string, mixed> $target
	 * @param list<string> $keys
	 */
	private function setNestedFileValue(array &$target, array $keys, mixed $value): void
	{
		$current = &$target;
		foreach ($keys as $index => $key) {
			if ($index === count($keys) - 1) {
				$current[$key] = $value;
				break;
			}

			if (! isset($current[$key]) || ! is_array($current[$key])) {
				$current[$key] = [];
			}

			$current = &$current[$key];
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @return array<string, mixed>
	 */
	private function mergeCollectionFiles(CollectionInterface $collection, array $input, array $files): array
	{
		foreach ($files as $name => $file) {
			if (! is_string($name) || ! $collection->fields->has($name)) {
				continue;
			}

			if (! $file instanceof UploadedFileInterface) {
				continue;
			}

			$input[$name] = $file;
		}

		return $input;
	}
}
