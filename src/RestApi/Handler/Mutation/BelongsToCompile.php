<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\RelationNode;

trait BelongsToCompile
{
	use RelationCompileSupport;

	public function reconcileRelation(RecordNode $source, RelationNode $relation): void
	{
		$this->normalizeSingleRelationChildren($source, $relation);

		if (count(array_filter($relation->children, static fn(RecordNode $child): bool => $child->relationIntent === 'desired')) > 0) {
			$relation->children = array_values(array_filter(
				$relation->children,
				static fn(RecordNode $child): bool => $child->relationIntent !== 'omitted'
			));
		}

		foreach ($relation->children as $child) {
			if ($child->relationIntent === 'omitted') {
				continue;
			}

			if ($child->plannedOperation === 'delete') {
				if ($child->operation !== 'delete') {
					$fields = $child->currentData
						?? $child->inputIdentity?->getValues()
						?? (is_array($child->relationData) ? $child->relationData : []);
					$child->retarget($relation->targetCollection, $fields, 'delete', $child->currentData);
				}

				continue;
			}

			$input = $child->relationData;
			if (! is_array($input)) {
				continue;
			}

			$data = $input;
			if ($child->inputIdentity === null) {
				$child->mergeFields($data);
				$child->retarget($relation->targetCollection, $child->fields, 'create');
				continue;
			}

			if (count($data) <= count($child->inputIdentity->getValues()) && $child->currentData === null) {
				continue;
			}

			$child->mergeFields($data);
			$child->retarget($relation->targetCollection, $child->fields, 'upsert', $child->currentData);
		}

		foreach ($relation->children as $child) {
			$this->materializeChildNode($source, $relation, $child);
		}
	}
}
