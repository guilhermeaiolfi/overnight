<?php

declare(strict_types=1);

namespace ON\Runtime;

use ON\Application;
use ON\Console\ConsoleExtension;
use RuntimeException;

class ConsoleRuntime implements RuntimeInterface
{
	public function run(Application $app): int
	{
		if (! $app->hasExtension(ConsoleExtension::class)) {
			throw new RuntimeException('ConsoleRuntime requires ConsoleExtension.');
		}

		$exitCode = $app->ext(ConsoleExtension::class)->run();

		return (int) $exitCode;
	}
}
