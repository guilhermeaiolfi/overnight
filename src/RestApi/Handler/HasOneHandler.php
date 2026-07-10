<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\RestApi\Handler\Mutation\ForeignKeyOnTargetApply;
use ON\RestApi\Handler\Mutation\HasOneNormalize;

final class HasOneHandler extends AbstractRelationHandler
{
	use ForeignKeyOnTargetApply;
	use HasOneNormalize;
}
