<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

interface ValueNode
{
	public function value(): mixed;
}
