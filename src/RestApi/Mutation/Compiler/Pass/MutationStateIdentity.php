<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler\Pass;

use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Mutation\NodeStateInterface;
use ON\RestApi\Mutation\ValueRef;

/**
 * Shared helper for applying resolved primary-key values into mutation state objects.
 */
trait MutationStateIdentity
{
	private function applyPrimaryKeyValues(NodeStateInterface $state, PrimaryKeyValue $identity): void
	{
		foreach ($identity->getValues() as $fieldName => $value) {
			if ($value instanceof ValueRef && $value->getState() === $state && $value->getField() === $fieldName) {
				continue;
			}

			$state->setValue($fieldName, $value);
		}
	}
}
