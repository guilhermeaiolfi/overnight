<?php

declare(strict_types=1);

namespace ON\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface RequestForwarderInterface
{
	public function processForward(
		string $path,
		?ServerRequestInterface $request = null,
		?string $method = null
	): ResponseInterface;
}
