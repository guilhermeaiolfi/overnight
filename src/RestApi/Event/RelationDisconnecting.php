<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

final class RelationDisconnecting extends AbstractRelationMutationEvent
{
	public function eventName(): string
	{
		return 'restapi.relation.disconnecting';
	}
}
