<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Mutation\CycleRecordCommitter;
use ON\RestApi\Mutation\RecordStore;
use ON\RestApi\Payload\DirectusRecordStoreBuilder;
use ON\RestApi\Payload\MutationInputPreparer;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;

use function ON\Mapper\map;

final class FilesAction implements RestActionInterface
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

		$collection = $this->getFilesCollection();
		$body = is_array($payload['body'] ?? null) ? $payload['body'] : [];

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
