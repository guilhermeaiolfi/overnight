<?php

declare(strict_types=1);

namespace ON\RestApi\Payload;

use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Payload\Action\BasicRelationAction;
use ON\RestApi\Payload\Action\CreateAction;
use ON\RestApi\Payload\Action\UpdateAction;
use ON\RestApi\Payload\Expander\RelationPayloadExpanderInterface;
use ON\RestApi\Payload\Node\MutationNodeSpec;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Payload\Node\RelationPayload;
use ON\RestApi\Payload\Parser\DirectusPayloadParser;
use ON\RestApi\Resolver\Sql\SqlDataSource;

final class PayloadNormalizer
{
	public function __construct(
		private readonly HandlerFactory $handlers,
		private readonly SqlDataSource $dataSource,
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
		$collection = $this->dataSource->getRegistry()->getCollection($node->collection);

		foreach ($node->relations as $relation) {
			$this->normalizeRelation($relation, $context->withCollection($collection, $context->source));
		}
	}

	public function normalizeRelation(RelationPayload $relation, MutationContext $context): void
	{
		$expander = $this->handlers->payloadExpander($context->collection, $relation->relationName);
		if ($expander === null) {
			return;
		}

		$resolved = [];
		foreach ($relation->actions as $action) {
			if ($action instanceof BasicRelationAction) {
				array_push($resolved, ...$expander->expandBasic($context, $action));
				continue;
			}

			$resolved[] = $action;
		}

		$relation->actions = $resolved;

		foreach ($relation->actions as $action) {
			$expander->resolveAction($context, $action, $this);
		}
	}

	public function buildNode(string $collectionName, array $data, string $operation): MutationNodeSpec
	{
		$collection = $this->dataSource->getRegistry()->getCollection($collectionName);
		$node = $this->parser->parseNode($collection, $data);
		$node->operation = $operation;

		$context = new MutationContext(
			$collection,
			new \ON\RestApi\Mutation\MutationState($collection, $data),
			$operation,
		);
		$this->normalizeNode($node, $context);

		return $node;
	}
}
