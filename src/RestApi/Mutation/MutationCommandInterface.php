<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\RestApi\Resolver\DataSourceInterface;

interface MutationCommandInterface
{
	public function isReady(): bool;

	public function execute(DataSourceInterface $dataSource): void;
}
