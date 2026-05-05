<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemCreate;
use ON\RestApi\Event\ItemDelete;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Event\ItemUpdate;
use ON\RestApi\Resolver\RestResolverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class RestApiService
{
	public function __construct(
		protected Registry $registry,
		protected ?RestResolverInterface $resolver,
		protected ?EventDispatcherInterface $eventDispatcher = null
	) {
	}

	public function getResolver(): ?RestResolverInterface
	{
		return $this->resolver;
	}

	public function getCollection(string $collectionName): CollectionInterface
	{
		$collection = $this->registry->getCollection($collectionName);

		if ($collection === null || $collection->isHidden()) {
			throw RestApiError::collectionNotFound($collectionName);
		}

		return $collection;
	}

	public function getCollections(): array
	{
		return $this->registry->getCollections();
	}

	public function parseFields(string|CollectionInterface $collection, ?string $fields): array
	{
		$collection = is_string($collection) ? $this->getCollection($collection) : $collection;

		return (new FieldSelector())->parse($collection, $fields);
	}

	public function getPrimaryKeyName(string|CollectionInterface $collection): string
	{
		$collection = $this->resolveCollection($collection);
		$pk = $collection->getPrimaryKey();

		if ($pk instanceof FieldInterface) {
			return $pk->getColumn();
		}

		if (is_array($pk) && isset($pk[0]) && $pk[0] instanceof FieldInterface) {
			return $pk[0]->getColumn();
		}

		return 'id';
	}

	public function list(string|CollectionInterface $collection, array $params = []): array
	{
		$collection = $this->resolveCollection($collection);
		$params = $this->normalizeParams($collection, $params);

		if ($this->shouldDispatchEvents($params)) {
			$event = new ItemList($collection, $params);
			$this->dispatchEvent($event);
			$this->assertAuthorized($event);

			if ($event->isDefaultPrevented()) {
				return [
					'items' => $event->getResult() ?? [],
					'meta' => $event->getTotalCount() === null ? [] : ['filter_count' => $event->getTotalCount()],
				];
			}
		}

		$result = $this->requireResolver()->list($collection, $params);
		if (isset($event)) {
			$event->setResult($result['items'] ?? [], $result['meta']['filter_count'] ?? null);
		}

		return $result;
	}

	public function get(string|CollectionInterface $collection, string $id, array $params = []): ?array
	{
		$collection = $this->resolveCollection($collection);
		$params = $this->normalizeParams($collection, $params);

		if (! $this->shouldDispatchEvents($params)) {
			return $this->requireResolver()->get($collection, $id, $params);
		}

		$event = new ItemGet($collection, $id, $params);
		$this->dispatchEvent($event);
		$this->assertAuthorized($event);

		if ($event->isDefaultPrevented()) {
			return $event->getResult();
		}

		$event->setResult($this->requireResolver()->get($collection, $id, $params));
		return $event->getResult();
	}

	public function create(string|CollectionInterface $collection, array $input, array $options = []): array
	{
		$collection = $this->resolveCollection($collection);
		$input = $this->handleFileUploads($collection, $input, $options['files'] ?? []);

		if (! $this->shouldDispatchEvents($options)) {
			return $this->requireResolver()->create($collection, $input);
		}

		$event = new ItemCreate($collection, $input);
		$this->dispatchEvent($event);
		$this->assertAuthorized($event);

		if ($event->isDefaultPrevented()) {
			return $event->getResult() ?? [];
		}

		$event->setResult($this->requireResolver()->create($collection, $event->getInput()));
		return $event->getResult();
	}

	public function createWithRelations(string|CollectionInterface $collection, array $input, array $nestedInput, array $options = []): array
	{
		$collection = $this->resolveCollection($collection);
		$input = $this->handleFileUploads($collection, $input, $options['files'] ?? []);

		if (! $this->shouldDispatchEvents($options)) {
			return $this->requireResolver()->createWithRelations($collection, $input, $nestedInput);
		}

		$event = new ItemCreate($collection, $input);
		$this->dispatchEvent($event);
		$this->assertAuthorized($event);

		if ($event->isDefaultPrevented()) {
			return $event->getResult() ?? [];
		}

		$event->setResult($this->requireResolver()->createWithRelations($collection, $event->getInput(), $nestedInput));
		return $event->getResult();
	}

	public function update(string|CollectionInterface $collection, string $id, array $input, array $options = []): ?array
	{
		$collection = $this->resolveCollection($collection);
		$this->checkIfMatch($collection, $id, $options['ifMatch'] ?? null);
		$input = $this->handleFileUploads($collection, $input, $options['files'] ?? []);

		if (! $this->shouldDispatchEvents($options)) {
			return $this->requireResolver()->update($collection, $id, $input);
		}

		$event = new ItemUpdate($collection, $id, $input);
		$this->dispatchEvent($event);
		$this->assertAuthorized($event);

		if ($event->isDefaultPrevented()) {
			return $event->getResult();
		}

		$updated = $this->requireResolver()->update($collection, $id, $event->getInput());
		if ($updated !== null) {
			$event->setResult($updated);
		}

		return $event->getResult();
	}

	public function updateWithRelations(string|CollectionInterface $collection, string $id, array $input, array $nestedInput, array $options = []): ?array
	{
		$collection = $this->resolveCollection($collection);
		$this->checkIfMatch($collection, $id, $options['ifMatch'] ?? null);
		$input = $this->handleFileUploads($collection, $input, $options['files'] ?? []);

		if (! $this->shouldDispatchEvents($options)) {
			return $this->requireResolver()->updateWithRelations($collection, $id, $input, $nestedInput);
		}

		$event = new ItemUpdate($collection, $id, $input);
		$this->dispatchEvent($event);
		$this->assertAuthorized($event);

		if ($event->isDefaultPrevented()) {
			return $event->getResult();
		}

		$updated = $this->requireResolver()->updateWithRelations($collection, $id, $event->getInput(), $nestedInput);
		if ($updated !== null) {
			$event->setResult($updated);
		}

		return $event->getResult();
	}

	public function delete(string|CollectionInterface $collection, string $id, array $options = []): bool
	{
		$collection = $this->resolveCollection($collection);
		$this->checkIfMatch($collection, $id, $options['ifMatch'] ?? null);

		if (! $this->shouldDispatchEvents($options)) {
			return $this->requireResolver()->delete($collection, $id);
		}

		$event = new ItemDelete($collection, $id);
		$this->dispatchEvent($event);
		$this->assertAuthorized($event);

		if ($event->isDefaultPrevented()) {
			return true;
		}

		return $this->requireResolver()->delete($collection, $id);
	}

	public function aggregate(string|CollectionInterface $collection, array $params = []): array
	{
		$collection = $this->resolveCollection($collection);
		$params = $this->normalizeParams($collection, $params);

		if (! $this->shouldDispatchEvents($params)) {
			return $this->requireResolver()->aggregate($collection, $params);
		}

		$event = new ItemList($collection, $params);
		$this->dispatchEvent($event);
		$this->assertAuthorized($event);

		if ($event->isDefaultPrevented()) {
			return $event->getResult() ?? [];
		}

		$result = $this->requireResolver()->aggregate($collection, $params);
		$event->setResult($result);

		return $event->getResult() ?? [];
	}

	public function clearCache(): void
	{
		$this->resolver?->clearCache();
	}

	public function computeETag(string $jsonBody): string
	{
		return 'W/"' . md5($jsonBody) . '"';
	}

	public function getItemETag(string|CollectionInterface $collection, string $id): string
	{
		$current = $this->get($collection, $id, ['dispatchEvents' => false]);

		if ($current === null) {
			throw RestApiError::notFound();
		}

		return $this->computeETag(json_encode(['data' => $current]));
	}

	protected function resolveCollection(string|CollectionInterface $collection): CollectionInterface
	{
		return is_string($collection) ? $this->getCollection($collection) : $collection;
	}

	protected function requireResolver(): RestResolverInterface
	{
		if ($this->resolver === null) {
			throw RestApiError::serviceUnavailable();
		}

		return $this->resolver;
	}

	public function dispatchEvent(object $event): void
	{
		$this->eventDispatcher?->dispatch($event);
	}

	protected function assertAuthorized(object $event): void
	{
		if (! $event instanceof AuthorizationAwareEventInterface) {
			return;
		}

		match ($event->getAuthState()) {
			AuthState::Allowed => null,
			AuthState::Unauthenticated => throw RestApiError::unauthenticated(),
			AuthState::Forbidden, AuthState::Pending => throw RestApiError::forbidden(),
		};
	}

	protected function checkIfMatch(CollectionInterface $collection, string $id, ?string $ifMatch): void
	{
		if ($ifMatch === null || $ifMatch === '') {
			return;
		}

		if ($ifMatch !== $this->getItemETag($collection, $id)) {
			throw RestApiError::preconditionFailed();
		}
	}

	protected function handleFileUploads(CollectionInterface $collection, array $input, array $files): array
	{
		if ($files === []) {
			return $input;
		}

		$fileFieldTypes = ['file', 'image', 'upload'];

		foreach ($collection->fields as $name => $field) {
			if (! in_array($field->getType(), $fileFieldTypes, true)) {
				continue;
			}

			if (! isset($files[$name])) {
				continue;
			}

			$event = new FileUpload($collection, $name, $files[$name]);
			$this->dispatchEvent($event);

			if ($event->getStoredPath() !== null) {
				$input[$name] = $event->getStoredPath();
			} else {
				throw RestApiError::fileHandlerMissing($name);
			}
		}

		return $input;
	}

	protected function normalizeParams(CollectionInterface $collection, array $params): array
	{
		if (! array_key_exists('fields', $params) || is_string($params['fields']) || $params['fields'] === null) {
			$params['fields'] = $this->parseFields($collection, $params['fields'] ?? null);
		}

		return $params;
	}

	protected function shouldDispatchEvents(array $options): bool
	{
		return $options['dispatchEvents'] ?? true;
	}
}
