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
use ON\RestApi\Payload\MutationInputMerger;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;

final class CreateAction implements RestActionInterface
{
	use RegistrySupportTrait;
	use ValidationTrait;

	public function __construct(
		private Registry $registry,
		private MutationCoordinator $mutations,
		private RestApiConfig $config,
		private ?FileUploadEventEmitter $fileUploadEventEmitter = null,
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

		if (isset($body[0]) && is_array($body[0])) {
			$inputs = [];
			foreach ($body as $item) {
				$this->validate($collection, $item);
				$input = $this->toPhpInput($collection, $item, $files, $options['input']);
				if ($this->fileUploadEventEmitter !== null) {
					$input = $this->fileUploadEventEmitter->processInput($collection, $input);
				}
				$inputs[] = $input;
			}

			return [
				'data' => $this->mutations->batchCreate(
					$collection,
					$inputs,
					(bool) $options['dispatchEvents'],
					$options['output'],
				),
			];
		}

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
		$input = $this->toPhpInput($collection, $item, $files, $options['input']);
		if ($this->fileUploadEventEmitter !== null) {
			$input = $this->fileUploadEventEmitter->processInput($collection, $input);
		}

		return $this->mutations->create(
			$collection,
			$input,
			[],
			(bool) $options['dispatchEvents'],
			$options['output'],
		);
	}

	/**
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $files
	 * @param class-string $from
	 * @return array<string, mixed>
	 */
	private function toPhpInput(
		CollectionInterface $collection,
		array $item,
		array $files,
		string $from,
	): array {
		if ($files !== []) {
			$item = (new MutationInputMerger())->mergeFiles($collection, $item, $files);
		}

		if ($from === PhpRepresentation::class) {
			return $item;
		}

		[$scalars, $relations] = MutationInput::splitNodeInput($collection, $item);
		if ($scalars !== []) {
			$scalars = map($scalars)
				->args($collection)
				->from($from)
				->as(PhpRepresentation::class)
				->to([]);
		}

		return $scalars + $relations;
	}
}
