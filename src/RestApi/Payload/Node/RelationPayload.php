<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Node;

use ON\RestApi\Payload\Action\RelationAction;

final class RelationPayload
{
	/**
	 * @param list<RelationAction> $actions
	 */
	public function __construct(
		public string $relationName,
		public string $targetCollection,
		public array $actions = [],
	) {
	}
}
