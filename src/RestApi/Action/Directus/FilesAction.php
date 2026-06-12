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
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationNodeBuilder;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Payload\Node\MutationSpec;
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
		private FileUploadEventEmitter $fileUploadEventEmitter,
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
		$this->validate($collection, $item);
		$spec = map($item)
			->using(DirectusMutationBuilder::class, $collection, 'create', null, $payload['files'] ?? [])
			->from($options['input'])
			->as(self::INTERMEDIATE_REPRESENTATION)
			->to(MutationSpec::class);

		$this->fileUploadEventEmitter->process($spec);
		$queue = new MutationQueue();
		$afterHooksTx = $this->hookDispatcher->start();
		$rootNode = MutationNodeBuilder::fromSpec($spec, 'create', $this->registry, $this->items, $this->relationHandlers);
		$root = null;
		if ($rootNode !== null) {
			$task = $queue->fill($rootNode, $this->hookDispatcher, $afterHooksTx, $options['dispatchEvents']);
			$root = $task instanceof MutationDeleteTaskInterface ? null : $task;
		}

		try {
			$result = $this->items->commit($queue, fn (): array => $root?->getRow() ?? []);
			$afterHooksTx->flush();
		} catch (\Throwable $throwable) {
			$afterHooksTx->rollback();
			throw $throwable;
		}

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
