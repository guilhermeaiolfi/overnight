<?php

declare(strict_types=1);

namespace Tests\ON\Fixtures;

use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MaintenanceHandler
{
	public bool $wasCalled = false;

	public function process(): ResponseInterface
	{
		$this->wasCalled = true;
		return new HtmlResponse('<h1>Maintenance</h1>', 503);
	}

	public function handle(): ResponseInterface
	{
		$this->wasCalled = true;
		return new HtmlResponse('<h1>Maintenance</h1>', 503);
	}

	public function reset(): void
	{
		$this->wasCalled = false;
	}
}
