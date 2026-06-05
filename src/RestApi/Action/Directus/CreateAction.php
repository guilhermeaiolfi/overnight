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
use ON\RestApi\Event\RestEventManager;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationPlan;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use Psr\EventDispatcher\EventDispatcherInterface;

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
		private FileUploadEventEmitter $fileUploadEventEmitter,
		private RestApiConfig $config,
		private EventDispatcherInterface $eventDispatcher,
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
		$this->validate($collection, $item);
		$spec = map($item)
			->using(DirectusMutationBuilder::class, $collection, 'create', null, $payload['files'] ?? [])
			->from($options['input'])
			->as(self::INTERMEDIATE_REPRESENTATION)
			->to(MutationSpec::class);

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

		return map($result)
			->using(CollectionRowMapper::class, $collection)
			->from(StorageRepresentation::class)
			->as($options['output'])
			->toArray();
	}
}
