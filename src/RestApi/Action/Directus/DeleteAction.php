<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\ETagTrait;
use ON\RestApi\Support\PrimaryKey;
use ON\Data\Key;
use ON\RestApi\Support\RegistrySupportTrait;

final class DeleteAction implements RestActionInterface
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
		$identity = PrimaryKey::of($collection)->getValue((string) ($params['id'] ?? ''));
		$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
		$this->checkIfMatch($collection, $identity, $this->getIfMatch($headers));

		$this->mutations->delete(
			$collection,
			$identity,
			(bool) $options['dispatchEvents'],
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
