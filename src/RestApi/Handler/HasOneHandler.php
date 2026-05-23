<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\RestApi\Handler\Mutation\ForeignKeyOnTargetApply;
use ON\RestApi\Handler\Read\SingularRelationRead;

class HasOneHandler extends AbstractRelationHandler implements RelationMutationHandlerInterface
{
	use SingularRelationRead;
	use ForeignKeyOnTargetApply;
}
