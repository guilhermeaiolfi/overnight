<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;

final class FilesAction implements RestActionInterface
{
	use RegistrySupportTrait;
	use ValidationTrait;

	public function __construct(
		private Registry $registry,
		private MutationCoordinator $mutations,
		private FileUploadEventEmitter $fileUploadEventEmitter,
		private RestApiConfig $config,
	) {
	}

	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		$payload = is_array($payload) ? $payload : [];
		$options = ($options ?? []) + [
			'dispatchEvents' => true,
			'input' => PhpRepresentation::class,
			'output' => PhpRepresentation::class,
		];

		$collection = $this->getFilesCollection();
		$body = is_array($payload['body'] ?? null) ? $payload['body'] : [];
		$files = is_array($payload['files'] ?? null) ? $payload['files'] : [];

		return ['data' => $this->createOne($collection, $body, $files, $options)];
	}

	/**
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $files
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function createOne(
		CollectionInterface $collection,
		array $item,
		array $files,
		array $options,
	): array {
		$this->validate($collection, $item);
		if ($files !== []) {
			foreach ($files as $field => $file) {
				$item[(string) $field] = $file;
			}
		}

		if ($options['input'] !== PhpRepresentation::class) {
			[$scalars, $relations] = MutationInput::splitNodeInput($collection, $item);
			if ($scalars !== []) {
				$scalars = map($scalars)
					->args($collection)
					->from($options['input'])
					->as(PhpRepresentation::class)
					->to([]);
			}
			$item = $scalars + $relations;
		}

		$item = $this->fileUploadEventEmitter->processInput($collection, $item);

		return $this->mutations->create(
			$collection,
			$item,
			[],
			(bool) $options['dispatchEvents'],
			$options['output'],
		);
	}

	private function getFilesCollection(): CollectionInterface
	{
		$collectionName = (string) $this->config->get('filesCollection', 'directus_files');
		$collection = $this->registry->getCollection($collectionName);

		if ($collection === null && $collectionName === 'directus_files') {
			$collectionName = 'files';
		}

		return $this->getCollectionOrThrow($this->registry, $collection ?? $collectionName);
	}
}
