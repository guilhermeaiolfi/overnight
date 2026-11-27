<?php

declare(strict_types=1);

namespace ON\Extension;

use ON\Init\Init;

interface ExtensionInterface
{
	public function id(): string;

	public function register(Init $init): void;
}
