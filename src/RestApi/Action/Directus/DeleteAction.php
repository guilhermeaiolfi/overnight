<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Mapper\Representation\PhpRepresentation;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Support\ETagTrait;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Mutation\CycleRecordCommitter;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\NodeState;
use ON\RestApi\Mutation\RecordStore;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;

final class DeleteAction implements RestActionInterface
{
	use ETagTrait;
	use RegistrySupportTrait;

	public function __construct(
		private Registry $registry,
		private ItemRepositoryInterface $items,
		private HandlerFactory $relationHandlers,
		private CycleRecordCommitter $committer,
		private RestApiConfig $config,
		private RestHookDispatcher $hookDispatcher,
	) {}

	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		$payload = is_array($payload) ? $payload : [];
		$options = ($options ?? []) + ['dispatchEvents' => true];
		$collection = $this->getCollectionOrThrow($this->registry, (string) ($params['collection'] ?? ''));
		$identity = $collection->getPrimaryKey()->getValue((string) ($params['id'] ?? ''));
		$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
		$this->checkIfMatch($collection, $identity, $this->getIfMatch($headers));

		$store = new RecordStore(new RecordNode(
			operation: 'delete',
			collection: $collection,
			state: new NodeState($collection, $identity->getValues()),
		));
		$result = $this->committer->commit($store, $options['dispatchEvents']);

		if ($result === null) {
			throw RestApiError::notFound();
		}

		return null;
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
