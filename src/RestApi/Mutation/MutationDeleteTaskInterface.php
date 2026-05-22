<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

interface MutationDeleteTaskInterface
{
	public function getResult(): bool;
}
