<?php

declare(strict_types=1);

namespace ON\RestApi\Action;

interface RestActionInterface
{
	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed;
}
