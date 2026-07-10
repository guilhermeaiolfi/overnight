<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Payload;

enum ToOneKind
{
	case Clear;
	case Existing;
	case New;
}
