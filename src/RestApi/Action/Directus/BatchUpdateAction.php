<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Support\ETagTrait;
use ON\RestApi\Support\FormatOutputTrait;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\RestEventManager;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationPlan;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use Psr\EventDispatcher\EventDispatcherInterface;

final class BatchUpdateAction implements RestActionInterface
{
	use ETagTrait;
	use FormatOutputTrait;
	use RegistrySupportTrait;
	use ValidationTrait;

	public function __construct(
		private Registry $registry,
		private ItemRepositoryInterface $items,
		private HandlerFactory $relationHandlers,
		private DirectusMutationBuilder $mutationBuilder,
		private FileUploadEventEmitter $fileUploadEventEmitter,
		private RestApiConfig $config,
		private ?EventDispatcherInterface $eventDispatcher = null,
	) {}

	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		$payload = is_array($payload) ? $payload : [];
		$options = ($options ?? []) + ['serialize' => true, 'dispatchEvents' => true];
		$collection = $this->getCollectionOrThrow($this->registry, (string) ($params['collection'] ?? ''));
		$body = is_array($payload['body'] ?? null) ? $payload['body'] : [];

		if (!isset($body[0]) || !is_array($body[0])) {
			throw new RestApiError('Batch update expects an array of objects with primary keys.', 'INVALID_PAYLOAD', null, 400);
		}

		$results = [];
		foreach ($body as $item) {
			$identity = $collection->getPrimaryKey()->extractFromInput($item);
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

			$item = $this->stripHiddenFields($collection, $item);
			$this->validate($collection, $item, true);
			$spec = $this->mutationBuilder->build($collection, $item, 'update', $identity, $payload['files'] ?? []);
			$identity = $collection->getPrimaryKey()->getValue($identity);
			$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
			$this->checkIfMatch($collection, $identity, $this->getIfMatch($headers));
			$this->fileUploadEventEmitter->process($spec);
			$queue = new MutationQueue();
			$events = new RestEventManager($this->eventDispatcher);
			$plan = MutationPlan::fromSpec($spec, 'update', $this->registry, $this->items, $this->relationHandlers, $identity);
			$root = null;
			if ($plan !== null) {
				if ($options['dispatchEvents']) {
					$events->dispatchBeforeEvents($plan->getBeforeMutationEvents($queue));
					$events->scheduleAfterEvent($plan->getAfterMutationEvents());
				}
				$task = $queue->fill($plan->root, $events, $options['dispatchEvents']);
				$root = $task instanceof MutationDeleteTaskInterface ? null : $task;
			}

			$result = $this->items->commit($queue, fn (): ?array => $root?->getRow());
			$events->dispatchAfterEvents();
			$results[] = $this->formatResponseRow($collection, $result, $options) ?? [];
		}

		return ['data' => $results];
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
