<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

final class RelationDisconnected extends AbstractRelationMutationEvent
{
	public function eventName(): string
	{
		return 'restapi.relation.disconnected';
	}
}
