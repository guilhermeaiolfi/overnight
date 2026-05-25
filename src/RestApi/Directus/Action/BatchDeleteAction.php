<?php

declare(strict_types=1);

namespace ON\RestApi\Directus\Action;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Action\Concern\ETagTrait;
use ON\RestApi\Action\Concern\FormatOutputTrait;
use ON\RestApi\Action\Concern\RegistrySupportTrait;
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

final class BatchDeleteAction implements RestActionInterface
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
		$collection = $this->getCollection((string) ($params['collection'] ?? ''));
		$body = is_array($payload['body'] ?? null) ? $payload['body'] : [];

		if (!is_array($body)) {
			throw new RestApiError('Batch delete expects an array of IDs.', 'INVALID_PAYLOAD', null, 400);
		}

		foreach ($body as $id) {
			$identity = is_array($id)
				? $collection->getPrimaryKey()->extractFromInput($id)
				: $this->decodeRouteIdentity($collection, (string) $id);
			if ($identity === null) {
				$missing = is_array($id) ? $collection->getPrimaryKey()->getMissingFieldNames($id) : $collection->getPrimaryKey()->getFieldNames();
				throw new RestApiError(
					"Batch delete item is missing primary key field(s): " . implode(', ', $missing) . '.',
					'MISSING_PRIMARY_KEY',
					$missing[0] ?? null,
					400
				);
			}

			$identity = $this->normalizeIdentity($collection, $identity);
			$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
			$this->checkIfMatch($collection, $identity, $this->getIfMatch($headers));

			$queue = new MutationQueue();
			$rootState = new MutationState($collection, $identity->values());
			$events = new RestEventManager($this->eventDispatcher);
			$plan = new MutationPlan(new MutationNode(
				operation: 'delete',
				collection: $collection,
				state: new MutationState($collection, $identity->values()),
			));
			if ($options['dispatchEvents']) {
				$events->dispatchBeforeEvents($plan->getBeforeMutationEvents($queue, $rootState));
				$events->scheduleAfterEvent($plan->getAfterMutationEvents($rootState));
			}
			$task = $queue->fill($plan->root, $events, $rootState, $options['dispatchEvents']);
			$deleted = $task instanceof MutationDeleteTaskInterface ? $task : null;

			$this->items->commit($queue, fn (): bool => $deleted?->getResult() ?? true);
			$events->dispatchAfterEvents();
		}

		return null;
	}

	protected function getItemForETag(CollectionInterface $collection, PrimaryKeyValue|string $identity): ?array
	{
		return $this->formatResponseRow(
			$collection,
			$this->items->findByIdentity($collection, $this->normalizeIdentity($collection, $identity), typed: false),
			[]
		);
	}
}
