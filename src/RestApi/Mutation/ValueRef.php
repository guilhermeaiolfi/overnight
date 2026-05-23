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

	public static function forStateField(MutationStateInterface $state, string $field): self
	{
		return new self($state, $field);
	}

	public function getState(): MutationStateInterface
	{
		return $this->state;
	}

	public function getField(): string
	{
		return $this->field;
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
