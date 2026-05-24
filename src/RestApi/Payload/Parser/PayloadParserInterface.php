<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Parser;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Payload\Node\MutationSpec;

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
