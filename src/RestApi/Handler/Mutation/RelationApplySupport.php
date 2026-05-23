<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\ValueRef;

trait RelationApplySupport
{
	protected function getPrimaryKeyValueFromState(
		MutationStateInterface $state,
		bool $requireReady = true
	): ?PrimaryKeyValue {
		$values = [];

		foreach ($state->getCollection()->getPrimaryKey()->getFieldNames() as $fieldName) {
			$value = $state->getValue($fieldName);
			if ($value instanceof ValueRef) {
				if (!$value->isReady() && $requireReady) {
					return null;
				}

				$values[$fieldName] = $value;
				continue;
			}

			if ($requireReady) {
				$value = $state->resolveValue($value);
			}

			if ($value === null && !$state->isValueReady($fieldName)) {
				return null;
			}

			$values[$fieldName] = $value;
		}

		return new PrimaryKeyValue($state->getCollection(), $values);
	}

	protected function linkForeignKeyOnSourceToTarget(
		MutationStateInterface $source,
		MutationStateInterface $target
	): void {
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$outerKey = $this->relation->outerKeys()[$index];
			$source->setValue($innerKey, ValueRef::forStateField($target, $outerKey));
		}
	}
}
