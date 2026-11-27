<?php

declare(strict_types=1);

namespace ON\Runtime;

use ON\Application;
use ON\Middleware\PipelineExtension;
use RuntimeException;

class HttpRuntime implements RuntimeInterface
{
	public function run(Application $app): int
	{
		if (! $app->hasExtension(PipelineExtension::class)) {
			throw new RuntimeException('HttpRuntime requires PipelineExtension.');
		}

		$app->ext(PipelineExtension::class)->run();

		return 0;
	}
}
