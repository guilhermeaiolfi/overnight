<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Parser;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\RecordNode;

interface PayloadParserInterface
{
	/**
	 * @param array<string, mixed> $input
	 */
	public function parse(
		CollectionInterface $collection,
		array $input,
	): RecordNode;

	/**
	 * @param array<string, mixed> $input
	 */
	public function parseNode(CollectionInterface $collection, array $input): RecordNode;
}
