<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

final class RelationConnecting extends AbstractRelationMutationEvent
{
	public function eventName(): string
	{
		return 'restapi.relation.connecting';
	}
}
