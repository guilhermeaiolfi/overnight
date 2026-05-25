<?php

declare(strict_types=1);

namespace ON\RestApi\Directus\Action;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Action\Concern\ETagTrait;
use ON\RestApi\Action\Concern\FormatOutputTrait;
use ON\RestApi\Action\Concern\RegistrySupportTrait;
use ON\RestApi\Action\Concern\ValidationTrait;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\RestEventManager;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationPlan;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use Psr\EventDispatcher\EventDispatcherInterface;

final class UpdateAction implements RestActionInterface
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
		$collection = $this->getCollection((string) ($params['collection'] ?? ''));
		$body = is_array($payload['body'] ?? null) ? $payload['body'] : [];
		$body = $this->stripHiddenFields($collection, $body);
		$this->validate($collection, $body, true);
		$identity = $this->decodeRouteIdentity($collection, (string) ($params['id'] ?? ''));
		$spec = $this->mutationBuilder->build($collection, $body, 'update', $identity, $payload['files'] ?? []);
		$identity = $this->normalizeIdentity($collection, $identity);
		$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
		$this->checkIfMatch($collection, $identity, $this->getIfMatch($headers));
		$this->fileUploadEventEmitter->process($spec);
		$queue = new MutationQueue();
		$rootState = new MutationState($collection, $spec->root->fields);
		$events = new RestEventManager($this->eventDispatcher);
		$plan = MutationPlan::fromSpec($this->registry, $this->items, $this->relationHandlers, 'update', $collection, $spec, $rootState, $identity);
		$root = null;
		if ($plan !== null) {
			if ($options['dispatchEvents']) {
				$events->dispatchBeforeEvents($plan->getBeforeMutationEvents($queue, $rootState));
				$events->scheduleAfterEvent($plan->getAfterMutationEvents($rootState));
			}
			$task = $queue->fill($plan->root, $events, $rootState, $options['dispatchEvents']);
			$root = $task instanceof MutationDeleteTaskInterface ? null : $task;
		}

		$result = $this->items->commit($queue, fn (): ?array => $root?->getRow());
		$events->dispatchAfterEvents();

		$result = $this->formatResponseRow($collection, $result, $options);
		if (empty($result)) {
			throw RestApiError::notFound();
		}

		return ['data' => $result];
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
