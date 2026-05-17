<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

enum SetOperator: string
{
	case In = 'in';
	case NotIn = 'notIn';
}
