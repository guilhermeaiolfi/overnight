<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

enum SortDirection: string
{
	case Asc = 'ASC';
	case Desc = 'DESC';
}
