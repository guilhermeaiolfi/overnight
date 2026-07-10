<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use function ON\Mapper\map;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationNodeBuilder;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\ETagTrait;
use ON\RestApi\Support\PrimaryKey;
use ON\RestApi\Support\PrimaryKeyValue;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;
use Throwable;

final class BatchUpdateAction implements RestActionInterface
{
	use ETagTrait;
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
	) {
	}

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

		if (! isset($body[0]) || ! is_array($body[0])) {
			throw new RestApiError('Batch update expects an array of objects with primary keys.', 'INVALID_PAYLOAD', null, 400);
		}

		$results = [];
		foreach ($body as $item) {
			$identity = PrimaryKey::of($collection)->extractFromInput($item);
			if ($identity === null) {
				$missing = PrimaryKey::of($collection)->getMissingFieldNames($item);

				throw new RestApiError(
					"Each item must include the primary key field(s): " . implode(', ', $missing) . '.',
					'MISSING_PRIMARY_KEY',
					$missing[0] ?? null,
					400
				);
			}

			foreach (PrimaryKey::of($collection)->getFieldNames() as $fieldName) {
				unset($item[$fieldName]);
			}
			foreach (PrimaryKey::of($collection)->getColumns() as $columnName) {
				unset($item[$columnName]);
			}

			$this->validate($collection, $item, true);
			$spec = map($item)
				->using(DirectusMutationBuilder::class, $collection, 'update', $identity, $payload['files'] ?? [])
				->from($options['input'])
				->as(self::INTERMEDIATE_REPRESENTATION)
				->to(MutationSpec::class);
			$identity = PrimaryKey::of($collection)->getValue($identity);
			$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
			$this->checkIfMatch($collection, $identity, $this->getIfMatch($headers));
			$this->fileUploadEventEmitter->process($spec);
			$queue = new MutationQueue();
			$afterHooksTx = $this->hookDispatcher->start();
			$rootNode = MutationNodeBuilder::fromSpec($spec, 'update', $this->registry, $this->items, $this->relationHandlers, $identity);
			$root = null;
			if ($rootNode !== null) {
				$task = $queue->fill($rootNode, $this->hookDispatcher, $afterHooksTx, $options['dispatchEvents']);
				$root = $task instanceof MutationDeleteTaskInterface ? null : $task;
			}

			try {
				$result = $this->items->commit($queue, fn (): ?array => $root?->getRow());
				$afterHooksTx->flush();
			} catch (Throwable $throwable) {
				$afterHooksTx->rollback();

				throw $throwable;
			}
			$results[] = $result !== null
				? map($result)
					->using(CollectionRowMapper::class, $collection)
					->from(StorageRepresentation::class)
					->as($options['output'])
					->toArray()
				: [];
		}

		return ['data' => $results];
	}

	protected function getItemForETag(CollectionInterface $collection, PrimaryKeyValue|string $identity): ?array
	{
		return $this->items->findByIdentity(
			$collection,
			PrimaryKey::of($collection)->getValue($identity),
			PhpRepresentation::class,
		);
	}
}
