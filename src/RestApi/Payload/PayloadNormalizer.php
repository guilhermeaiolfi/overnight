<?php

declare(strict_types=1);

namespace ON\RestApi\Payload;

use ON\Data\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\RelationMutationHandlerInterface;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Payload\Node\MutationNodeSpec;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Payload\Node\RelationPayload;
use ON\RestApi\Payload\Parser\DirectusPayloadParser;

final class PayloadNormalizer
{
	private const READ_ONLY_RELATION_INPUT_POLICY = 'restapi::readOnlyInput';
	private const READ_ONLY_RELATION_INPUT_ERROR = 'error';

	public function __construct(
		private readonly HandlerFactory $handlers,
		private readonly Registry $registry,
		private readonly DirectusPayloadParser $parser = new DirectusPayloadParser(),
	) {
	}

	public function normalize(MutationSpec $spec, MutationContext $context): MutationSpec
	{
		$this->normalizeNode($spec->root, $context);

		return $spec;
	}

	public function normalizeNode(MutationNodeSpec $node, MutationContext $context): void
	{
		$collection = $this->registry->getCollection($node->collection);

		foreach ($node->relations as $relation) {
			$this->normalizeRelation($relation, $context->withCollection($collection, $context->source));
		}
	}

	public function normalizeRelation(RelationPayload $relation, MutationContext $context): void
	{
		$handler = $this->handlers->mutation($context->collection, $relation->relationName);
		if (! $handler instanceof RelationMutationHandlerInterface) {
			$definition = $context->collection->relations->get($relation->relationName);
			if ($definition->metadata(self::READ_ONLY_RELATION_INPUT_POLICY) === self::READ_ONLY_RELATION_INPUT_ERROR) {
				throw new RestApiError(
					"Relation '{$relation->relationName}' is read-only.",
					'READ_ONLY_RELATION',
					$relation->relationName,
					400
				);
			}

			$relation->actions = [];

			return;
		}

		$handler->normalizeRelation($relation, $context, $this);
	}

	public function normalizeChildNode(string $collectionName, array $data, string $operation): MutationNodeSpec
	{
		$collection = $this->registry->getCollection($collectionName);
		$node = $this->parser->parseNode($collection, $data);
		$node->operation = $operation;

		$context = new MutationContext(
			$collection,
			new MutationState($collection, $data),
			$operation,
		);
		$this->normalizeNode($node, $context);

		return $node;
	}
}
