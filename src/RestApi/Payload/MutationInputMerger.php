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

			if (is_array($relationInput) && MutationInput::isAssociativeArray($relationInput)) {
				$input[$relationName] = $this->mergeFiles($targetCollection, $relationInput, $relationFiles);
				continue;
			}

			foreach (MutationInput::normalizeRelationItems($relationInput) as $index => $item) {
				if (! is_array($item)) {
					continue;
				}

				$input[$relationName][$index] = $this->mergeFiles(
					$targetCollection,
					$item,
					is_array($relationFiles[$index] ?? null) ? $relationFiles[$index] : []
				);
			}
		}

		return $input;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @return array<string, mixed>
	 */
	private function mergeCollectionFiles(CollectionInterface $collection, array $input, array $files): array
	{
		$fileFieldTypes = ['file', 'image', 'upload'];

		foreach ($collection->fields as $name => $field) {
			if (! in_array($field->getType(), $fileFieldTypes, true)) {
				continue;
			}

			if (! isset($files[$name]) || ! $files[$name] instanceof UploadedFileInterface) {
				continue;
			}

			$input[$name] = $files[$name];
		}

		return $input;
	}
}
