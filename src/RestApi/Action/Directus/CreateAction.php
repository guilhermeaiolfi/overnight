<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\Definition\Registry;
use ON\Data\Key;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Payload\MutationInputMerger;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;

final class CreateAction implements RestActionInterface
{
	use RegistrySupportTrait;
	use ValidationTrait;
	use WriteInputTrait;

	public function __construct(
		private Registry $registry,
		private MutationCoordinator $mutations,
		private RestApiConfig $config,
		// Required (no `= null`): PHP-DI skips optional deps and would leave uploads unconverted.
		private FileUploadEventEmitter $fileUploadEventEmitter,
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
		$collection = $this->getCollectionOrThrow($this->registry, (string) ($params['collection'] ?? ''));
		$body = is_array($payload['body'] ?? null) ? $payload['body'] : [];
		$files = is_array($payload['files'] ?? null) ? $payload['files'] : [];

		$prepare = fn (array $input, ?Key $key, array $validation): array => $this->prepareInput($collection, $input, $validation);

		if ($body !== [] && array_is_list($body)) {
			$this->assertObjectBatch($body, 'Batch create expects an array of objects.');
			$batch = (new MutationInputMerger())->mergeFilesForBatch($collection, $body, $files);

			return ['data' => $this->mutations->batchCreate($collection, $batch, (bool) $options['dispatchEvents'], $options['output'], $prepare, $options['input'])];
		}

		return [
			'data' => $this->mutations->create(
				$collection,
				$body,
				$files,
				(bool) $options['dispatchEvents'],
				$options['output'],
				$prepare,
				$options['input'],
			),
		];
	}
}
