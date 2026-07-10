<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Payload\Action\CreateAction;
use ON\RestApi\Payload\Action\RelationAction;
use ON\RestApi\Payload\Action\UpdateAction;
use ON\RestApi\Payload\Node\MutationNodeSpec;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Payload\Node\RelationPayload;
use Psr\Http\Message\UploadedFileInterface;

final class FileUploadEventEmitter
{
	public function __construct(
		private readonly Registry $registry,
		private readonly RestHookDispatcher $hooks,
	) {
	}

	public function process(MutationSpec $spec): void
	{
		$this->processNode($spec->root);
	}

	private function processNode(MutationNodeSpec $node): void
	{
		$collection = $this->registry->getCollection($node->collection);
		$node->fields = $this->processFields($collection, $node->fields);

		foreach ($node->relations as $relation) {
			$this->processRelation($relation);
		}
	}

	private function processRelation(RelationPayload $relation): void
	{
		foreach ($relation->actions as $action) {
			$this->processAction($action);
		}
	}

	private function processAction(RelationAction $action): void
	{
		if (! $action instanceof CreateAction && ! $action instanceof UpdateAction) {
			return;
		}

		if ($action->node !== null) {
			$this->processNode($action->node);

			return;
		}

		if ($action->collection === null || ! is_array($action->data) || $action->data === []) {
			return;
		}

		$collection = $this->registry->getCollection($action->collection);
		if ($collection === null) {
			return;
		}

		$action->data = $this->processFields($collection, $action->data);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function processFields(CollectionInterface $collection, array $fields): array
	{
		foreach ($fields as $name => $value) {
			if (! $value instanceof UploadedFileInterface) {
				continue;
			}

			$fields[$name] = $this->emitFileUpload($collection, (string) $name, $value);
		}

		return $fields;
	}

	private function emitFileUpload(
		CollectionInterface $collection,
		string $fieldName,
		UploadedFileInterface $file
	): mixed {
		$event = new FileUpload($collection, $fieldName, $file);
		$this->hooks->dispatch($collection, 'file.upload', $event, false);

		if ($event->getStoredValue() !== null) {
			return $event->getStoredValue();
		}

		if ($event->getStoredPath() !== null) {
			return $event->getStoredPath();
		}

		throw RestApiError::fileHandlerMissing($fieldName);
	}
}
