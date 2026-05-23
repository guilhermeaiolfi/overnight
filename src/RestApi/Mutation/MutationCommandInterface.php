<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\RestApi\Repository\ItemRepositoryInterface;

interface MutationCommandInterface
{
	public function isReady(): bool;

	public function execute(ItemRepositoryInterface $repository): void;
}
