<?php

declare(strict_types=1);

namespace ON\RestApi\Mapping;

use ON\ORM\Definition\Collection\CollectionInterface;

interface CollectionMapperInterface
{
	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public function hydrate(CollectionInterface $collection, array $row): array;

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public function dehydrate(CollectionInterface $collection, array $row, bool $partial = false): array;
}
