<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Key;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\ETagTrait;
use ON\RestApi\Support\PrimaryKey;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\ValidationTrait;

final class UpdateAction implements RestActionInterface
{
	use ETagTrait;
	use RegistrySupportTrait;
	use ValidationTrait;
	use WriteInputTrait;

	public function __construct(
		private Registry $registry,
		private MutationCoordinator $mutations,
		private ItemRepositoryInterface $items,
		private RestApiConfig $config,
		// Required (no `= null`): PHP-DI skips optional deps and would leave uploads unconverted.
		private FileUploadEventEmitter $fileUploadEventEmitter,
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
		$files = is_array($payload['files'] ?? null) ? $payload['files'] : [];
		$identity = PrimaryKey::of($collection)->parseUrlId((string) ($params['id'] ?? ''));
		$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
		$ifMatch = $this->getIfMatch($headers);
		$prepare = function (array $input, Key $identity, array $validation) use ($collection, $ifMatch): array {
			$this->checkIfMatch($collection, $identity, $ifMatch);

			return $this->prepareInput($collection, $input, $validation, partial: true);
		};

		$result = $this->mutations->update(
			$collection,
			$identity,
			$body,
			$files,
			(bool) $options['dispatchEvents'],
			$options['output'],
			$prepare,
			$options['input'],
		);

		if ($result === []) {
			throw RestApiError::notFound();
		}

		return ['data' => $result];
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
