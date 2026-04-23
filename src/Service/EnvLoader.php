<?php

declare(strict_types=1);

namespace ON\Service;

use ON\Event\HasEventNameInterface;
use Symfony\Component\Dotenv\Dotenv;

class EnvLoader implements HasEventNameInterface
{
	public function __invoke()
	{
		// load .env file
		$dotenv = new Dotenv();
		if (file_exists(".env")) {
			$dotenv->load(".env");
		}
	}

	public function eventName(): string
	{
		return "on.init";
	}
}
