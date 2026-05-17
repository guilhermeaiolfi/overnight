<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

enum RelationLoadHint
{
	case InLoad;
	case PostLoad;
	case Join;
	case LeftJoin;
}
