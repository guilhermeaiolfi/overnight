<?php

declare(strict_types=1);

namespace ON\Middleware;

use Psr\Http\Message\ServerRequestInterface;

interface RequestPreparerInterface
{
	public function prepare(ServerRequestInterface $request): ServerRequestInterface;
}
