<?php

declare(strict_types=1);

namespace ON\Auth\Storage;

interface AuthLifecycleStorageInterface
{
	public function onLogin(): void;

	public function onLogout(): void;
}
