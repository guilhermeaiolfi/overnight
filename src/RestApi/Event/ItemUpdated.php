<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

class ItemUpdated extends ItemCreated
{
	public function eventName(): string
	{
		return 'restapi.item.updated';
	}
}
