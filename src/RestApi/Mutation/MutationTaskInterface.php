<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

interface MutationTaskInterface
{
	public function getState(): MutationStateInterface;

	public function getRow(): ?array;
}
