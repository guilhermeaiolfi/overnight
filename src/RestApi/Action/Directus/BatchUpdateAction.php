<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Support\ETagTrait;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationNodeBuilder;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;

use function ON\Mapper\map;

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

		if (!isset($body[0]) || !is_array($body[0])) {
			throw new RestApiError('Batch update expects an array of objects with primary keys.', 'INVALID_PAYLOAD', null, 400);
		}

		$results = [];
		foreach ($body as $item) {
			$identity = $collection->getPrimaryKey()->extract($item);
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

			$this->validate($collection, $item, true);
			$spec = map($item)
				->using(DirectusMutationBuilder::class, $collection, 'update', $identity, $payload['files'] ?? [])
				->from($options['input'])
				->as(self::INTERMEDIATE_REPRESENTATION)
				->to(MutationSpec::class);
			$identity = $collection->getPrimaryKey()->getValue($identity);
			$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
			$this->checkIfMatch($collection, $identity, $this->getIfMatch($headers));
			$this->fileUploadEventEmitter->process($spec);
			$queue = new MutationQueue();
			$afterHooksTx = $this->hookDispatcher->start();
			$rootNode = MutationNodeBuilder::fromSpec($spec, 'update', $this->registry, $this->items, $this->relationHandlers, $identity);
			$root = null;
			if ($rootNode !== null) {
				$root = $queue->fill($rootNode, $this->hookDispatcher, $afterHooksTx, $options['dispatchEvents']);
			}

			try {
				$result = $this->items->commit($queue, fn (): ?array => $root?->getRow());
				$afterHooksTx->flush();
			} catch (\Throwable $throwable) {
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
			$collection->getPrimaryKey()->getValue($identity),
			PhpRepresentation::class,
		);
	}
}
