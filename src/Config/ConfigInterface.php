<?php

declare(strict_types=1);

namespace ON\Config;

interface ConfigInterface
{
	public function get($string): mixed;

	public function all();
}
