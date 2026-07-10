<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use function ON\Mapper\map;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Payload\MutationInputMerger;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\ETagTrait;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\PrimaryKey;
use ON\RestApi\Support\PrimaryKeyValue;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;

final class BatchUpdateAction implements RestActionInterface
{
	use ETagTrait;
	use RegistrySupportTrait;
	use ValidationTrait;

	public function __construct(
		private Registry $registry,
		private MutationCoordinator $mutations,
		private ItemRepositoryInterface $items,
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

		if (! isset($body[0]) || ! is_array($body[0])) {
			throw new RestApiError('Batch update expects an array of objects with primary keys.', 'INVALID_PAYLOAD', null, 400);
		}

		$batch = [];
		foreach ($body as $item) {
			$identity = PrimaryKey::of($collection)->extractFromInput($item);
			if ($identity === null) {
				$missing = PrimaryKey::of($collection)->getMissingFieldNames($item);

				throw new RestApiError(
					"Each item must include the primary key field(s): " . implode(', ', $missing) . '.',
					'MISSING_PRIMARY_KEY',
					$missing[0] ?? null,
					400
				);
			}

			foreach (PrimaryKey::of($collection)->getFieldNames() as $fieldName) {
				unset($item[$fieldName]);
			}
			foreach (PrimaryKey::of($collection)->getColumns() as $columnName) {
				unset($item[$columnName]);
			}

			$this->validate($collection, $item, true);
			$identityValue = PrimaryKey::of($collection)->getValue($identity);
			$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
			$this->checkIfMatch($collection, $identityValue, $this->getIfMatch($headers));

			$input = $this->toPhpInput($collection, $item, $files, $options['input']);
			if ($this->fileUploadEventEmitter !== null) {
				$input = $this->fileUploadEventEmitter->processInput($collection, $input);
			}

			$batch[] = [
				'identity' => $identityValue,
				'input' => $input,
			];
		}

		return [
			'data' => $this->mutations->batchUpdate(
				$collection,
				$batch,
				(bool) $options['dispatchEvents'],
				$options['output'],
			),
		];
	}

	protected function getItemForETag(CollectionInterface $collection, PrimaryKeyValue|string $identity): ?array
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
				->using(CollectionRowMapper::class, $collection)
				->from($from)
				->as(PhpRepresentation::class)
				->toArray();
		}

		return $scalars + $relations;
	}
}
