<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

enum AggregateFunction: string
{
	case Count = 'count';
	case Sum = 'sum';
	case Avg = 'avg';
	case Min = 'min';
	case Max = 'max';
}
