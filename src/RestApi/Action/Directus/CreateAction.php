<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Support\FormatOutputTrait;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Event\RestEventManager;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationPlan;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use Psr\EventDispatcher\EventDispatcherInterface;

final class CreateAction implements RestActionInterface
{
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
		$item = $this->stripHiddenFields($collection, $item);
		$this->validate($collection, $item);
		$spec = $this->mutationBuilder->build($collection, $item, 'create', files: $payload['files'] ?? []);

		$this->fileUploadEventEmitter->process($spec);
		$queue = new MutationQueue();
		$events = new RestEventManager($this->eventDispatcher);
		$plan = MutationPlan::fromSpec($spec, 'create', $this->registry, $this->items, $this->relationHandlers);
		$root = null;
		if ($plan !== null) {
			if ($options['dispatchEvents']) {
				$events->dispatchBeforeEvents($plan->getBeforeMutationEvents($queue));
				$events->scheduleAfterEvent($plan->getAfterMutationEvents());
			}
			$task = $queue->fill($plan->root, $events, $options['dispatchEvents']);
			$root = $task instanceof MutationDeleteTaskInterface ? null : $task;
		}

		$result = $this->items->commit($queue, fn (): array => $root?->getRow() ?? []);
		$events->dispatchAfterEvents();

		return $this->formatResponseRow($collection, $result, $options) ?? [];
	}
}
