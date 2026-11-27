<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\RestApi\Action\RestActionInterface;

interface RestApiExecutorInterface
{
	public function execute(string|RestActionInterface $action, array $params = [], mixed $payload = null, ?array $options = null): mixed;

	public function ensureActivated(): void;
}
