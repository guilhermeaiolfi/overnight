<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Action;

final class BasicRelationAction implements RelationAction
{
	/**
	 * @param list<mixed> $items
	 */
	public function __construct(
		public array $items = [],
		public mixed $item = null,
	) {
	}
}
