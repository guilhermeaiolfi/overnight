<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Payload;

use ON\Data\Definition\Relation\RelationInterface;

/**
 * Directus protocol intent for one relation field. Persistence details stay in ON\Data.
 */
interface RelationMutation
{
	public function relation(): RelationInterface;

	public function path(): PayloadPath;
}
