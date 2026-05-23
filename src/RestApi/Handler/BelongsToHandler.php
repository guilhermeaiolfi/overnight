<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\RestApi\Handler\Mutation\BelongsToMutation;
use ON\RestApi\Handler\Read\SingularRelationRead;

class BelongsToHandler extends AbstractRelationHandler implements RelationMutationHandlerInterface
{
	use SingularRelationRead;
	use BelongsToMutation;
}
