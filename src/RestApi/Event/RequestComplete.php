<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;

class RequestComplete implements HasEventNameInterface
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
