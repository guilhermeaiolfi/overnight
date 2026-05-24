<?php

declare(strict_types=1);

namespace ON\RestApi\Payload;

use ON\ORM\Definition\Registry;
use ON\RestApi\Mutation\ValueRef;
use ON\RestApi\Payload\Action\CreateAction;
use ON\RestApi\Payload\Action\RelationAction;
use ON\RestApi\Payload\Action\UpdateAction;
use ON\RestApi\Payload\Node\MutationNodeSpec;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Payload\Node\RelationPayload;
use ON\RestApi\Serialize\CollectionSerializer;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Wire-format field values → PHP domain values on the entry pipeline.
 */
final class MutationSpecUnserializer
{
	public function __construct(
		private readonly Registry $registry,
		private readonly CollectionSerializer $serializer = new CollectionSerializer(),
	) {
	}

	public function unserialize(MutationSpec $spec, bool $partial = false): MutationSpec
	{
		$this->unserializeNode($spec->root, $partial);

		return $spec;
	}

	private function unserializeNode(MutationNodeSpec $node, bool $partial): void
	{
		$collection = $this->registry->getCollection($node->collection);
		$node->fields = $this->unserializeFields($collection, $node->fields, $partial);

		foreach ($node->relations as $relation) {
			$this->unserializeRelation($relation, $partial);
		}
	}

	private function unserializeRelation(RelationPayload $relation, bool $partial): void
	{
		foreach ($relation->actions as $action) {
			$this->unserializeAction($action, $partial);
		}
	}

	private function unserializeAction(RelationAction $action, bool $partial): void
	{
		if ($action instanceof CreateAction || $action instanceof UpdateAction) {
			if ($action->node !== null) {
				$this->unserializeNode($action->node, $action instanceof UpdateAction || $partial);
			}
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function unserializeFields(
		\ON\ORM\Definition\Collection\CollectionInterface $collection,
		array $fields,
		bool $partial
	): array {
		if ($fields === []) {
			return $fields;
		}

		$wireInput = [];
		foreach ($fields as $name => $value) {
			if ($value instanceof ValueRef || $value instanceof UploadedFileInterface) {
				continue;
			}

			$wireInput[$name] = $value;
		}

		if ($wireInput === []) {
			return $fields;
		}

		$php = $this->serializer->unserialize($collection, $wireInput, $partial);

		foreach ($php as $name => $value) {
			$fields[$name] = $value;
		}

		return $fields;
	}
}
