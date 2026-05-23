<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Action;

use ON\ORM\Definition\Collection\PrimaryKeyValue;

final class DisconnectAction implements RelationAction
{
	public function __construct(
		public ?string $collection = null,
		public PrimaryKeyValue|int|string|null $target = null,
		public int $index = 0,
		public bool $explicitOperation = false,
	) {
	}
}
