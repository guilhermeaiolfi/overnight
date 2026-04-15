<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use League\Event\HasEventName;

class RequestComplete implements HasEventName
{
	public function __construct(
		protected mixed $data
	) {
	}

	public function eventName(): string
	{
		return 'restapi.request.complete';
	}

	public function getData(): mixed
	{
		return $this->data;
	}
}
