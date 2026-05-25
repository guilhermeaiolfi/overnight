<?php

declare(strict_types=1);

namespace ON\RestApi\Directus\Action;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Action\Concern\FormatOutputTrait;
use ON\RestApi\Action\Concern\RegistrySupportTrait;
use ON\RestApi\Action\Concern\ValidationTrait;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Event\RestEventManager;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationPlan;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationState;
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
		$collection = $this->getCollection((string) ($params['collection'] ?? ''));
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
		$rootState = new MutationState($collection, $spec->root->fields);
		$events = new RestEventManager($this->eventDispatcher);
		$plan = MutationPlan::fromSpec($this->registry, $this->items, $this->relationHandlers, 'create', $collection, $spec, $rootState);
		$root = null;
		if ($plan !== null) {
			if ($options['dispatchEvents']) {
				$events->dispatchBeforeEvents($plan->getBeforeMutationEvents($queue, $rootState));
				$events->scheduleAfterEvent($plan->getAfterMutationEvents($rootState));
			}
			$task = $queue->fill($plan->root, $events, $rootState, $options['dispatchEvents']);
			$root = $task instanceof MutationDeleteTaskInterface ? null : $task;
		}

		$result = $this->items->commit($queue, fn (): array => $root?->getRow() ?? []);
		$events->dispatchAfterEvents();

		return $this->formatResponseRow($collection, $result, $options) ?? [];
	}
}
