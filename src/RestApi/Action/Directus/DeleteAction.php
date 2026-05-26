<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Support\ETagTrait;
use ON\RestApi\Support\FormatOutputTrait;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\RestEventManager;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationPlan;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use Psr\EventDispatcher\EventDispatcherInterface;

final class DeleteAction implements RestActionInterface
{
	use ETagTrait;
	use FormatOutputTrait;
	use RegistrySupportTrait;

	public function __construct(
		private Registry $registry,
		private ItemRepositoryInterface $items,
		private HandlerFactory $relationHandlers,
		private RestApiConfig $config,
		private ?EventDispatcherInterface $eventDispatcher = null,
	) {}

	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		$payload = is_array($payload) ? $payload : [];
		$options = ($options ?? []) + ['serialize' => true, 'dispatchEvents' => true];
		$collection = $this->getCollectionOrThrow($this->registry, (string) ($params['collection'] ?? ''));
		$identity = $collection->getPrimaryKey()->getValue((string) ($params['id'] ?? ''));
		$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
		$this->checkIfMatch($collection, $identity, $this->getIfMatch($headers));

		$queue = new MutationQueue();
		$rootState = new MutationState($collection, $identity->values());
		$events = new RestEventManager($this->eventDispatcher);
		$plan = new MutationPlan(new MutationNode(
			operation: 'delete',
			collection: $collection,
			state: $rootState,
		));
		if ($options['dispatchEvents']) {
			$events->dispatchBeforeEvents($plan->getBeforeMutationEvents($queue));
			$events->scheduleAfterEvent($plan->getAfterMutationEvents());
		}
		$task = $queue->fill($plan->root, $events, $options['dispatchEvents']);
		$deleted = $task instanceof MutationDeleteTaskInterface ? $task : null;

		$result = $this->items->commit($queue, fn (): bool => $deleted?->getResult() ?? true);
		$events->dispatchAfterEvents();

		if (!$result) {
			throw RestApiError::notFound();
		}

		return null;
	}

	protected function getItemForETag(CollectionInterface $collection, PrimaryKeyValue|string $identity): ?array
	{
		return $this->formatResponseRow(
			$collection,
			$this->items->findByIdentity($collection, $collection->getPrimaryKey()->getValue($identity), typed: false),
			['serialize' => false]
		);
	}
}
