<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\RestApi\Handler\Mutation\ForeignKeyOnTargetApply;
use ON\RestApi\Handler\Mutation\HasManyNormalize;

final class HasManyHandler extends AbstractRelationHandler
{
	use ForeignKeyOnTargetApply;
	use HasManyNormalize;
}
