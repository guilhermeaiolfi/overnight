<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Mapper\Representation\PhpRepresentation;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\ETagTrait;
use ON\RestApi\Support\PrimaryKey;
use ON\RestApi\Support\PrimaryKeyValue;
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
		$body = is_array($payload['body'] ?? null) ? $payload['body'] : [];

		if (! is_array($body)) {
			throw new RestApiError('Batch delete expects an array of IDs.', 'INVALID_PAYLOAD', null, 400);
		}

		foreach ($body as $id) {
			$identity = is_array($id)
				? PrimaryKey::of($collection)->extractFromInput($id)
				: PrimaryKey::of($collection)->getValue((string) $id);
			if ($identity === null) {
				$missing = is_array($id) ? PrimaryKey::of($collection)->getMissingFieldNames($id) : PrimaryKey::of($collection)->getFieldNames();

				throw new RestApiError(
					"Batch delete item is missing primary key field(s): " . implode(', ', $missing) . '.',
					'MISSING_PRIMARY_KEY',
					$missing[0] ?? null,
					400
				);
			}

			$identity = PrimaryKey::of($collection)->getValue($identity);
			$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
			$this->checkIfMatch($collection, $identity, $this->getIfMatch($headers));

			$this->mutations->delete(
				$collection,
				$identity,
				(bool) $options['dispatchEvents'],
			);
		}

		return null;
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
