<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Parser;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Support\PrimaryKeyValue;

interface PayloadParserInterface
{
	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @param 'create'|'update'|'upsert' $mode
	 */
	public function parse(
		CollectionInterface $collection,
		array $input,
		string $mode = 'upsert',
		PrimaryKeyValue|string|null $id = null,
		array $files = [],
	): MutationSpec;
}
