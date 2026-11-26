<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Key;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\ETagTrait;
use ON\RestApi\Support\PrimaryKey;
use ON\RestApi\Support\RegistrySupportTrait;

final class BatchDeleteAction implements RestActionInterface
{
	use ETagTrait;
	use RegistrySupportTrait;

	public function __construct(
		private Registry $registry,
		private MutationCoordinator $mutations,
		private ItemRepositoryInterface $items,
		private RestApiConfig $config,
	) {
	}

	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		$payload = is_array($payload) ? $payload : [];
		$options = ($options ?? []) + ['dispatchEvents' => true];
		$collection = $this->getCollectionOrThrow($this->registry, (string) ($params['collection'] ?? ''));
		$body = $payload['body'] ?? null;
		if (! is_array($body) || ! array_is_list($body) || $body === []) {
			throw new RestApiError('Batch delete expects an array of IDs.', 'INVALID_PAYLOAD', null, 400);
		}

		if (count($body) > max(1, (int) $this->config->get('maxBatchSize', 1000))) {
			throw new RestApiError('Too many items in a single batch.', 'BATCH_LIMIT_EXCEEDED', null, 400);
		}
		$primaryKey = PrimaryKey::of($collection);
		$identities = [];
		foreach ($body as $id) {
			$identity = match (true) {
				is_array($id) => $primaryKey->extractFromInput($id),
				is_string($id), is_int($id) => $primaryKey->parseUrlId((string) $id),
				default => null,
			};
			if ($identity === null) {
				throw new RestApiError('Batch delete expects valid primary keys.', 'INVALID_PRIMARY_KEY', null, 400);
			}

			$identities[] = $identity;
		}

		$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
		$ifMatch = $this->getIfMatch($headers);

		$this->mutations->batchDelete(
			$collection,
			$identities,
			(bool) $options['dispatchEvents'],
			fn (Key $key) => $this->checkIfMatch($collection, $key, $ifMatch),
		);

		return null;
	}

	protected function getItemForETag(CollectionInterface $collection, Key|string $identity): ?array
	{
		return $this->items->findByIdentity(
			$collection,
			PrimaryKey::of($collection)->getValue($identity),
			PhpRepresentation::class,
		);
	}
}
