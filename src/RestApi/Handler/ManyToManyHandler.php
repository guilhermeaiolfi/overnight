<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\RestApi\Handler\Mutation\ManyToManyApply;
use ON\RestApi\Handler\Mutation\ManyToManyNormalize;

final class ManyToManyHandler extends AbstractRelationHandler
{
	use ManyToManyApply;
	use ManyToManyNormalize;
}
