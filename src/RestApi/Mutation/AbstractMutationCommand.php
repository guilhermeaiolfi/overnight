<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;


abstract class AbstractMutationCommand implements MutationCommandInterface
{
	public function isReady(): bool
	{
		return true;
	}

	protected function valuesReady(mixed $value): bool
	{
		if ($value instanceof ValueRef) {
			return $value->isReady();
		}

		if (is_array($value)) {
			foreach ($value as $item) {
				if (! $this->valuesReady($item)) {
					return false;
				}
			}
		}

		return true;
	}

	protected function resolveValue(mixed $value): mixed
	{
		if ($value instanceof ValueRef) {
			return $value->resolve();
		}

		if (is_array($value)) {
			foreach ($value as $key => $item) {
				$value[$key] = $this->resolveValue($item);
			}
		}

		return $value;
	}
}
