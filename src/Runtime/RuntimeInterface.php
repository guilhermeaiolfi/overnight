<?php

declare(strict_types=1);

namespace ON\Runtime;

use ON\Application;

interface RuntimeInterface
{
	public function run(Application $app): int;
}
