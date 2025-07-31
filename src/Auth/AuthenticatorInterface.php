<?php

declare(strict_types=1);

namespace ON\Auth;

use ON\Auth\Exception\ExceptionInterface;

interface AuthenticatorInterface
{
	/**
	 * Performs an authentication attempt
	 *
	 * @return Result
	 * @throws ExceptionInterface If authentication cannot be performed.
	 */
	public function authenticate(): Result;
}
