<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Mutation\CycleRecordCommitter;
use ON\RestApi\Mutation\RecordStore;
use ON\RestApi\Payload\DirectusRecordStoreBuilder;
use ON\RestApi\Payload\MutationInputPreparer;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;

use function ON\Mapper\map;

final class CreateAction implements RestActionInterface
{
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

		if (isset($body[0]) && is_array($body[0])) {
			$results = [];
			foreach ($body as $item) {
				$results[] = $this->createOne($collection, $item, $payload, $options);
			}

			return ['data' => $results];
		}

		return ['data' => $this->createOne($collection, $body, $payload, $options)];
	}

	private function createOne(CollectionInterface $collection, array $item, array $payload, array $options): array
	{
		$item = $this->inputPreparer->prepare($collection, $item, is_array($payload['files'] ?? null) ? $payload['files'] : []);
		$this->validate($collection, $item);
		$store = map($item)
			->using(DirectusRecordStoreBuilder::class, $collection, 'create')
			->from($options['input'])
			->as(self::INTERMEDIATE_REPRESENTATION)
			->to(RecordStore::class);

		$result = $store !== null
			? $this->committer->commit($store, $options['dispatchEvents']) ?? []
			: [];

		return map($result)
			->using(CollectionRowMapper::class, $collection)
			->from(StorageRepresentation::class)
			->as($options['output'])
			->toArray();
	}

}
