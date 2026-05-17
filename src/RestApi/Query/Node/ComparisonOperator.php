<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

enum ComparisonOperator: string
{
	case Eq = 'eq';
	case Neq = 'neq';
	case Lt = 'lt';
	case Lte = 'lte';
	case Gt = 'gt';
	case Gte = 'gte';
	case Contains = 'contains';
	case NotContains = 'ncontains';
	case StartsWith = 'startsWith';
	case EndsWith = 'endsWith';
}
