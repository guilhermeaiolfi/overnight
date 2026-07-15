<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Payload\MutationInputMerger;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\ETagTrait;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\PrimaryKey;
use ON\Data\Key;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;
use ON\RestApi\Repository\ItemRepositoryInterface;

final class UpdateAction implements RestActionInterface
{
	use ETagTrait;
	use RegistrySupportTrait;
	use ValidationTrait;

	public function __construct(
		private Registry $registry,
		private MutationCoordinator $mutations,
		private ItemRepositoryInterface $items,
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
		$this->validate($collection, $body, true);
		$identity = PrimaryKey::of($collection)->getValue((string) ($params['id'] ?? ''));
		$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
		$this->checkIfMatch($collection, $identity, $this->getIfMatch($headers));

		$input = $this->fileUploadEventEmitter->processInput(
			$collection,
			$this->toPhpInput($collection, $body, $files, $options['input']),
		);
		$result = $this->mutations->update(
			$collection,
			$identity,
			$input,
			[],
			(bool) $options['dispatchEvents'],
			$options['output'],
		);

		if ($result === []) {
			throw RestApiError::notFound();
		}

		return ['data' => $result];
	}

	protected function getItemForETag(CollectionInterface $collection, Key|string $identity): ?array
	{
		return $this->items->findByIdentity(
			$collection,
			PrimaryKey::of($collection)->getValue($identity),
			PhpRepresentation::class,
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
