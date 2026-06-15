<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Support\ETagTrait;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Mutation\CycleRecordCommitter;
use ON\RestApi\Mutation\RecordStore;
use ON\RestApi\Payload\DirectusRecordStoreBuilder;
use ON\RestApi\Payload\MutationInputPreparer;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;

use function ON\Mapper\map;

final class BatchUpdateAction implements RestActionInterface
{
	use ETagTrait;
	use RegistrySupportTrait;
	use ValidationTrait;

	private const INTERMEDIATE_REPRESENTATION = PhpRepresentation::class;

	public function __construct(
		private Registry $registry,
		private ItemRepositoryInterface $items,
		private HandlerFactory $relationHandlers,
		private MutationInputPreparer $inputPreparer,
		private CycleRecordCommitter $committer,
		private RestApiConfig $config,
		private RestHookDispatcher $hookDispatcher,
	) {}

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

		if (!isset($body[0]) || !is_array($body[0])) {
			throw new RestApiError('Batch update expects an array of objects with primary keys.', 'INVALID_PAYLOAD', null, 400);
		}

		$results = [];
		foreach ($body as $item) {
			$identity = $collection->getPrimaryKey()->extract($item);
			if ($identity === null) {
				$missing = $collection->getPrimaryKey()->getMissingFieldNames($item);
				throw new RestApiError(
					"Each item must include the primary key field(s): " . implode(', ', $missing) . '.',
					'MISSING_PRIMARY_KEY',
					$missing[0] ?? null,
					400
				);
			}

			foreach ($collection->getPrimaryKey()->getFieldNames() as $fieldName) {
				unset($item[$fieldName]);
			}
			foreach ($collection->getPrimaryKey()->getColumns() as $columnName) {
				unset($item[$columnName]);
			}

			$item = $this->inputPreparer->prepare($collection, $item, is_array($payload['files'] ?? null) ? $payload['files'] : []);
			$this->validate($collection, $item, true);
			$store = map($item)
				->using(DirectusRecordStoreBuilder::class, $collection, 'update', $identity)
				->from($options['input'])
				->as(self::INTERMEDIATE_REPRESENTATION)
				->to(RecordStore::class);
			$identity = $collection->getPrimaryKey()->getValue($identity);
			$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
			$this->checkIfMatch($collection, $identity, $this->getIfMatch($headers));
			$result = $store !== null
				? $this->committer->commit($store, $options['dispatchEvents'])
				: null;
			$results[] = $result !== null
				? map($result)
					->using(CollectionRowMapper::class, $collection)
					->from(StorageRepresentation::class)
					->as($options['output'])
					->toArray()
				: [];
		}

		return ['data' => $results];
	}

	protected function getItemForETag(CollectionInterface $collection, PrimaryKeyValue|string $identity): ?array
	{
		return $this->items->findByIdentity(
			$collection,
			$collection->getPrimaryKey()->getValue($identity),
			PhpRepresentation::class,
		);
	}
}
