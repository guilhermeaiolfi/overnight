<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\RestApi\Handler\Mutation\BelongsToApply;
use ON\RestApi\Handler\Mutation\BelongsToNormalize;

final class BelongsToHandler extends AbstractRelationHandler
{
	use BelongsToApply;
	use BelongsToNormalize;
}
