<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

enum AuthState
{
	case Pending;
	case Allowed;
	case Unauthenticated;
	case Forbidden;
}
