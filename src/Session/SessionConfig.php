<?php

declare(strict_types=1);

namespace ON\Session;

use ON\Config\Config;
use ON\Session\Native\NativeSession;

class SessionConfig extends Config
{
	public string $class = NativeSession::class;

	public array $options = [
		'use_strict_mode' => true,
		'use_only_cookies' => true,
		'cookie_httponly' => true,
		'cookie_samesite' => 'Lax',
	];
}
