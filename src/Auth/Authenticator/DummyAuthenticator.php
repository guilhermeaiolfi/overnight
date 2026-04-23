<?php

declare(strict_types=1);

namespace ON\Auth\Authenticator;

use ON\Auth\AuthenticatorInterface;
use ON\Auth\Exception\NotImplementedException;
use ON\Auth\Result;

class DummyAuthenticator implements AuthenticatorInterface
{
	public function authenticate(): Result
	{
		throw new NotImplementedException("You should implement your own adapter and link it to ON\Auth\AuthenticatorInterface in your container.");
	}
}
