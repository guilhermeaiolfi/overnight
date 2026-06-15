<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\RelationNode;

trait ForeignKeyOnTargetCompile
{
	use RelationCompileSupport;

	public function reconcileRelation(RecordNode $source, RelationNode $relation): void
	{
		if ($relation->definition->getCardinality() === 'single') {
			$this->normalizeSingleRelationChildren($source, $relation);
		} else {
			$this->normalizeManyRelationChildren($source, $relation);
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
			$data = $this->prepareForeignKeyInput($source, $relation, $data);
			$child->relationData = $data;
			if ($child->inputIdentity === null) {
				$child->mergeFields($data);
				$child->retarget($relation->targetCollection, $child->fields, 'create');
				continue;
			}

			if ($this->isSingle() || $child->currentData !== null || count($data) > count($child->inputIdentity->getValues())) {
				$child->mergeFields($data);
				$child->retarget($relation->targetCollection, $child->fields, 'upsert', $child->currentData);
			}
		}

		foreach ($relation->children as $child) {
			$this->materializeChildNode($source, $relation, $child);
		}
	}

	private function prepareForeignKeyInput(RecordNode $source, RelationNode $relation, array $input): array
	{
		$this->applySourceValuesToTargetInput($relation->definition, $input, $source->state);

		return $input;
	}
}
