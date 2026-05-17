<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

enum LogicalOperator: string
{
	case And = 'and';
	case Or = 'or';
}
