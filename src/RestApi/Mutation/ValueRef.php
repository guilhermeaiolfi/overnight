<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

final class ValueRef
{
	public function __construct(
		private MutationStateInterface $state,
		private string $field
	) {
	}

	public function resolve(): mixed
	{
		return $this->state->resolveValue($this->field);
	}

	public function isReady(): bool
	{
		return $this->state->isValueReady($this->field);
	}
}
