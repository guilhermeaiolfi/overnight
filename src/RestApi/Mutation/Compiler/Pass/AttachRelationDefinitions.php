<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler\Pass;

use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Mutation\Compiler\HydrationPassInterface;
use ON\RestApi\Mutation\Compiler\HydrationSubjectInterface;
use ON\RestApi\Mutation\RecordNode;

/**
 * Resolves relation definitions, target collections, and mutation handlers for each relation node.
 */
final class AttachRelationDefinitions implements HydrationPassInterface
{
	private const READ_ONLY_RELATION_INPUT_POLICY = 'restapi::readOnlyInput';
	private const READ_ONLY_RELATION_INPUT_ERROR = 'error';

	public function __construct(
		private readonly HandlerFactory $handlers,
	) {
	}

	public function run(HydrationSubjectInterface $subject): HydrationSubjectInterface
	{
		if (! $subject instanceof RecordNode) {
			throw new \InvalidArgumentException('AttachRelationDefinitions requires a record node.');
		}

		foreach ($subject->relations as $name => $relationNode) {
			if (!$subject->collection->relations->has($relationNode->relationName)) {
				unset($subject->relations[$name]);
				continue;
			}

			$definition = $subject->collection->relations->get($relationNode->relationName);
			$relationNode->definition = $definition;
			$relationNode->targetCollection = $definition->getCollection();
			$relationNode->handler = $this->handlers->mutation($subject->collection, $relationNode->relationName);

			if ($relationNode->handler === null) {
				if ($definition->metadata(self::READ_ONLY_RELATION_INPUT_POLICY) === self::READ_ONLY_RELATION_INPUT_ERROR) {
					throw new \ON\RestApi\Error\RestApiError(
						"Relation '{$relationNode->relationName}' is read-only.",
						'READ_ONLY_RELATION',
						$relationNode->relationName,
						400
					);
				}

				$relationNode->children = [];
			}
		}

		return $subject;
	}
}
