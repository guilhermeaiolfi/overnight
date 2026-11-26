<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Key;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;

final class FilesAction implements RestActionInterface
{
	use RegistrySupportTrait;
	use ValidationTrait;
	use WriteInputTrait;

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

		return [
			'data' => $this->mutations->create(
				$collection,
				array_replace($body, $files),
				[],
				(bool) $options['dispatchEvents'],
				$options['output'],
				fn (array $input, ?Key $key, array $validation): array => $this->prepareInput($collection, $input, $validation),
				$options['input'],
			),
		];
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
